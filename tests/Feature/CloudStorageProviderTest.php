<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Tests\Feature;

use Google\Service\Drive\DriveFile;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Justus\FlysystemOneDrive\OneDriveAdapter;
use League\Flysystem\Config;
use Masbug\Flysystem\GoogleDriveAdapter;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\DriveItem;
use Microsoft\Graph\Model\UploadSession;
use SchoolPalm\StorageApi\Contracts\StorageOAuthCredentialStore;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;
use SchoolPalm\StorageApi\Exceptions\StorageAuthorizationException;
use SchoolPalm\StorageApi\OAuth\StorageOAuthService;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Settings\OAuthTokenState;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Storage\CloudFilesystemFactory;
use SchoolPalm\StorageApi\Storage\RetryingOneDriveAdapter;
use SchoolPalm\StorageApi\Tests\TestCase;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;

class CloudStorageProviderTest extends TestCase
{
    public function test_oauth_provider_schemas_declare_permissions_and_secret_fields(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);

        foreach (['gdrive', 'dropbox', 'onedrive'] as $provider) {
            $metadata = $registry->definition($provider)->toArray();
            $fields = collect($metadata['fields'])->keyBy('name');

            $this->assertTrue($metadata['authorization']['required']);
            $this->assertNotEmpty($metadata['authorization']['scopes']);
            $this->assertTrue($fields['clientSecret']['secret']);
            $this->assertTrue($fields['refreshToken']['secret']);
            $this->assertSame($provider, $metadata['capabilities']['provider']);
        }

        $this->assertFalse($registry->definition('gdrive')->capabilities->supportsTemporaryUrl);
        $this->assertFalse($registry->definition('dropbox')->capabilities->supportsPublicUrl);
        $this->assertFalse($registry->definition('onedrive')->capabilities->supportsTemporaryUrl);
        $this->assertSame(3, $registry->definition('gdrive')->capabilities->retryAttempts);
        $this->assertSame(3, $registry->definition('dropbox')->capabilities->retryAttempts);
        $this->assertSame(9, $registry->definition('onedrive')->capabilities->retryAttempts);
    }

    public function test_cloud_provider_schemas_validate_required_client_ids(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);

        foreach (['gdrive', 'dropbox', 'onedrive'] as $provider) {
            try {
                $registry->validateConfiguration($provider, ['clientId' => []]);
                $this->fail("Provider [$provider] must reject a non-string client ID.");
            } catch (InvalidStorageConfigurationException $exception) {
                $this->assertStringContainsString('clientId', $exception->getMessage());
            }
        }
    }

    public function test_oauth_authorization_urls_use_scopes_state_and_offline_access(): void
    {
        $oauth = $this->oauth([]);
        $url = $oauth->authorizationUrl(
            'onedrive',
            ['clientId' => 'client-id', 'tenantId' => 'school-tenant'],
            'https://app.example.test/oauth/callback',
            'opaque-csrf-state',
        );

        $this->assertStringContainsString('school-tenant/oauth2/v2.0/authorize', $url);
        $this->assertStringContainsString('offline_access', $url);
        $this->assertStringContainsString('opaque-csrf-state', $url);
        $this->assertStringContainsString('Files.ReadWrite', $url);
    }

    public function test_oauth_supports_pkce_s256_for_authorization_and_code_exchange(): void
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=').'abcde';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = $this->oauth([])->authorizationUrl(
            'gdrive',
            ['clientId' => 'client-id'],
            'https://app.example.test/callback',
            'opaque-state',
            $verifier,
        );
        $this->assertStringContainsString('code_challenge='.$challenge, $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"access","expires_in":3600}'),
        ]));
        $stack->push(Middleware::history($history));
        $oauth = new StorageOAuthService(new Client(['handler' => $stack]));
        $oauth->exchangeAuthorizationCode(
            'gdrive',
            ['clientId' => 'client-id', 'clientSecret' => 'client-secret'],
            'one-time-code',
            'https://app.example.test/callback',
            $verifier,
        );

        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertSame($verifier, $form['code_verifier']);
    }

    public function test_refresh_token_exchange_works_for_all_default_cloud_providers(): void
    {
        $responses = [];
        foreach (['gdrive', 'dropbox', 'onedrive'] as $provider) {
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => $provider.'-access',
                'refresh_token' => $provider.'-refresh',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR));
        }
        $oauth = $this->oauth($responses);

        foreach (['gdrive', 'dropbox', 'onedrive'] as $provider) {
            $credentials = $oauth->refresh($provider, [
                'clientId' => 'client-id',
                'clientSecret' => 'client-secret',
                'tenantId' => 'tenant-id',
                'refreshToken' => $provider.'-old-refresh',
            ]);

            $this->assertSame($provider.'-access', $credentials['accessToken']);
            $this->assertSame($provider.'-refresh', $credentials['refreshToken']);
        }
    }

    public function test_authorization_code_exchange_normalizes_oauth_tokens(): void
    {
        $oauth = $this->oauth([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'short-lived-access',
                'refresh_token' => 'long-lived-refresh',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $credentials = $oauth->exchangeAuthorizationCode(
            'dropbox',
            ['clientId' => 'app-key', 'clientSecret' => 'app-secret'],
            'one-time-code',
            'https://app.example.test/callback',
        );

        $this->assertSame('short-lived-access', $credentials['accessToken']);
        $this->assertSame('long-lived-refresh', $credentials['refreshToken']);
        $this->assertGreaterThan(time(), strtotime($credentials['accessTokenExpiresAt']));
    }

    public function test_expired_access_tokens_refresh_and_persist_in_the_active_context(): void
    {
        $oauth = $this->oauth([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'rotated-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $store = new InMemoryOAuthCredentialStore;
        $context = new StorageContext(tenantId: 'tenant-a', schoolId: 'school-a');
        $state = new OAuthTokenState(
            'onedrive',
            [
                'clientId' => 'client-id',
                'clientSecret' => 'client-secret',
                'tenantId' => 'tenant-a',
                'accessToken' => 'expired-access',
                'refreshToken' => 'old-refresh',
                'accessTokenExpiresAt' => gmdate(DATE_ATOM, time() - 60),
            ],
            $context,
            'school-drive',
            $oauth,
            $store,
        );

        $this->assertSame('rotated-access', $state->accessToken());
        $this->assertSame($context, $store->context);
        $this->assertSame('school-drive', $store->connection);
        $this->assertSame('onedrive', $store->provider);
        $this->assertSame('rotated-refresh', $store->credentials['refreshToken']);
    }

    public function test_google_sdk_refresh_callback_can_persist_tokens_to_the_active_connection(): void
    {
        $store = new InMemoryOAuthCredentialStore;
        $context = new StorageContext(tenantId: 'tenant-a', schoolId: 'school-a');
        $state = new OAuthTokenState(
            'gdrive',
            ['refreshToken' => 'google-refresh'],
            $context,
            'school-drive',
            $this->oauth([]),
            $store,
        );

        $state->persistAccessToken('google-rotated-access', 1800);

        $this->assertSame($context, $store->context);
        $this->assertSame('school-drive', $store->connection);
        $this->assertSame('gdrive', $store->provider);
        $this->assertSame('google-rotated-access', $store->credentials['accessToken']);
        $this->assertSame('google-refresh', $store->credentials['refreshToken']);
        $this->assertGreaterThan(time(), strtotime($store->credentials['accessTokenExpiresAt']));
    }

    public function test_revoked_oauth_authorization_returns_a_sanitized_error(): void
    {
        $oauth = $this->oauth([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","refresh_token":"secret-value"}'),
        ]);

        try {
            $oauth->refresh('gdrive', [
                'clientId' => 'client-id',
                'clientSecret' => 'client-secret',
                'refreshToken' => 'secret-value',
            ]);
            $this->fail('A revoked token must require reauthorization.');
        } catch (StorageAuthorizationException $exception) {
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
            $this->assertStringContainsString('reconnect', $exception->getMessage());
        }
    }

    public function test_default_cloud_providers_build_filesystems_without_host_disk_registration(): void
    {
        $factory = new CloudFilesystemFactory($this->oauth([]));
        $configuration = [
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
            'accessToken' => 'valid-access',
            'refreshToken' => 'valid-refresh',
            'accessTokenExpiresAt' => gmdate(DATE_ATOM, time() + 3600),
            'folderId' => 'folder-id',
        ];

        $google = $factory->build('gdrive', $configuration, null, 'drive');
        $dropbox = $factory->build('dropbox', $configuration, null, 'dropbox');
        $onedrive = $factory->build('onedrive', $configuration + ['driveId' => 'drive-id'], null, 'onedrive');

        $this->assertInstanceOf(FilesystemAdapter::class, $google);
        $this->assertInstanceOf(FilesystemAdapter::class, $dropbox);
        $this->assertInstanceOf(FilesystemAdapter::class, $onedrive);
        $this->assertTrue(class_exists(GoogleDriveAdapter::class));
        $this->assertTrue(class_exists(DropboxAdapter::class));
        $this->assertTrue(class_exists(OneDriveAdapter::class));
    }

    public function test_google_drive_adapter_retrieves_metadata_by_provider_file_id(): void
    {
        $adapter = new GoogleDriveAdapter(
            new FakeGoogleDriveService,
            'root',
            ['useDisplayPaths' => false],
        );

        $metadata = $adapter->getFileObject('root/drive-file-id');

        $this->assertInstanceOf(DriveFile::class, $metadata);
        $this->assertSame('drive-file-id', $metadata->getId());
    }

    public function test_dropbox_adapter_follows_list_folder_cursors(): void
    {
        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'entries' => [[
                        '.tag' => 'file',
                        'name' => 'first.txt',
                        'path_display' => '/first.txt',
                        'size' => 1,
                        'server_modified' => '2026-01-01T00:00:00Z',
                    ]],
                    'cursor' => 'cursor-next',
                    'has_more' => true,
                ], JSON_THROW_ON_ERROR)),
                new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'entries' => [[
                        '.tag' => 'file',
                        'name' => 'second.txt',
                        'path_display' => '/second.txt',
                        'size' => 2,
                        'server_modified' => '2026-01-02T00:00:00Z',
                    ]],
                    'cursor' => 'cursor-last',
                    'has_more' => false,
                ], JSON_THROW_ON_ERROR)),
            ])),
        ]);
        $adapter = new DropboxAdapter(new DropboxClient('test-access', $http));
        $items = iterator_to_array($adapter->listContents('', false));

        $this->assertSame(['first.txt', 'second.txt'], array_map(
            static fn ($item): string => $item->path(),
            $items,
        ));
    }

    public function test_onedrive_adapter_writes_to_the_selected_drive_file_path(): void
    {
        $graph = new FakeMicrosoftGraph;
        $adapter = new OneDriveAdapter($graph, 'drive-id', ['directory_type' => 'drives']);

        $adapter->write('report.csv', 'content', new Config);

        $this->assertSame('PUT', $graph->requests[1]['method']);
        $this->assertSame('/drives/drive-id/items/parent-id:/report.csv:/content', $graph->requests[1]['endpoint']);
        $this->assertSame('content', $graph->requests[1]['body']);
    }

    public function test_onedrive_chunk_upload_retries_rate_limits_using_retry_after(): void
    {
        Http::fake([
            'https://upload.example.test/session' => Http::sequence()
                ->push('', 429, ['Retry-After' => '0'])
                ->push('', 201),
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'payload');
        rewind($stream);

        $adapter = new RetryingOneDriveAdapter(
            new FakeMicrosoftGraph,
            'drive-id',
            ['directory_type' => 'drives'],
        );
        $adapter->writeStream('report.csv', $stream, new Config);

        Http::assertSentCount(2);
        fclose($stream);
    }

    private function oauth(array $responses): StorageOAuthService
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);

        return new StorageOAuthService($client);
    }
}

final class InMemoryOAuthCredentialStore implements StorageOAuthCredentialStore
{
    public ?StorageContext $context = null;

    public string $connection = '';

    public string $provider = '';

    public array $credentials = [];

    public function save(
        ?StorageContext $context,
        string $connection,
        string $provider,
        array $credentials,
    ): void {
        $this->context = $context;
        $this->connection = $connection;
        $this->provider = $provider;
        $this->credentials = $credentials;
    }
}

final class FakeMicrosoftGraph extends Graph
{
    public array $requests = [];

    public function createRequest($requestType, $endpoint)
    {
        $request = new FakeMicrosoftGraphRequest($requestType, $endpoint);
        $this->requests[] = &$request->record;

        return $request;
    }
}

final class FakeMicrosoftGraphRequest
{
    public array $record;

    public function __construct(string $method, string $endpoint)
    {
        $this->record = ['method' => $method, 'endpoint' => $endpoint, 'body' => null];
    }

    public function setReturnType(string $returnType): self
    {
        $this->record['returnType'] = $returnType;

        return $this;
    }

    public function addHeaders(array $headers): self
    {
        $this->record['headers'] = $headers;

        return $this;
    }

    public function attachBody(mixed $body): self
    {
        $this->record['body'] = $body;

        return $this;
    }

    public function execute(): mixed
    {
        if (($this->record['returnType'] ?? null) === DriveItem::class) {
            $item = new DriveItem;
            $item->setId('parent-id');

            return $item;
        }
        if (($this->record['returnType'] ?? null) === UploadSession::class) {
            $session = new UploadSession;
            $session->setUploadUrl('https://upload.example.test/session');

            return $session;
        }

        return null;
    }
}

final class FakeGoogleDriveService
{
    public FakeGoogleDriveFiles $files;

    public function __construct()
    {
        $this->files = new FakeGoogleDriveFiles;
    }

    public function getClient(): FakeGoogleDriveClient
    {
        return new FakeGoogleDriveClient;
    }

    public function createBatch(): FakeGoogleDriveBatch
    {
        return new FakeGoogleDriveBatch;
    }
}

final class FakeGoogleDriveFiles
{
    public function get(string $id, array $options): DriveFile
    {
        $file = new DriveFile;
        $file->setId($id);
        $file->setName('report.csv');
        $file->setMimeType('text/csv');

        return $file;
    }
}

final class FakeGoogleDriveClient
{
    public function isAccessTokenExpired(): bool
    {
        return false;
    }

    public function setUseBatch(bool $enabled): void {}
}

final class FakeGoogleDriveBatch
{
    private array $requests = [];

    public function add(mixed $request, string $key): void
    {
        $this->requests[$key] = $request;
    }

    public function execute(): array
    {
        return $this->requests;
    }
}
