<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use SchoolPalm\StorageApi\Contracts\StorageOAuthCredentialStore;
use SchoolPalm\StorageApi\Exceptions\StorageAuthorizationException;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageProviderException;
use SchoolPalm\StorageApi\OAuth\DropboxOAuthTokenProvider;
use SchoolPalm\StorageApi\OAuth\RefreshingMicrosoftGraph;
use SchoolPalm\StorageApi\OAuth\StorageOAuthService;
use SchoolPalm\StorageApi\Settings\OAuthTokenState;
use SchoolPalm\StorageApi\Settings\StorageContext;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;

final class CloudFilesystemFactory
{
    public function __construct(
        private readonly StorageOAuthService $oauth,
        private readonly ?StorageOAuthCredentialStore $credentialStore = null,
    ) {}

    public function build(
        string $provider,
        array $configuration,
        ?StorageContext $context,
        string $connection,
    ): LaravelFilesystemAdapter {
        $state = new OAuthTokenState(
            $provider,
            $configuration,
            $context,
            $connection,
            $this->oauth,
            $this->credentialStore,
        );

        $adapter = match ($provider) {
            'gdrive' => $this->googleDrive($state),
            'dropbox' => $this->dropbox($state, $configuration),
            'onedrive' => $this->oneDrive($state, $configuration),
            default => throw new UnsupportedStorageProviderException('The requested cloud storage provider is unavailable.'),
        };

        $filesystem = new Filesystem($adapter);

        return new LaravelFilesystemAdapter($filesystem, $adapter, $configuration);
    }

    private function googleDrive(OAuthTokenState $state): GoogleDriveAdapter
    {
        $configuration = $state->configuration();
        $client = new GoogleClient([
            'retry' => ['retries' => 3],
        ]);
        $client->setClientId((string) ($configuration['clientId'] ?? ''));
        $client->setClientSecret((string) ($configuration['clientSecret'] ?? ''));
        $client->setApplicationName('SchoolPalm Storage API');
        $expiresAt = strtotime((string) ($configuration['accessTokenExpiresAt'] ?? ''));
        $client->setAccessToken([
            'access_token' => $state->accessToken(),
            'refresh_token' => $configuration['refreshToken'] ?? null,
            'created' => time(),
            'expires_in' => $expiresAt === false ? 3600 : max(0, $expiresAt - time()),
        ]);
        $client->setTokenCallback(function (string $cacheKey, string $accessToken) use ($client, $state): void {
            $state->persistAccessToken($accessToken);
            $credentials = $state->configuration();
            $client->setAccessToken([
                'access_token' => $accessToken,
                'refresh_token' => $credentials['refreshToken'] ?? null,
                'created' => time(),
                'expires_in' => 3600,
            ]);
        });

        $drive = new Drive($client);
        $folderId = $configuration['folderId'] ?? null;
        $teamDriveId = $configuration['teamDriveId'] ?? null;
        $options = match (true) {
            is_string($teamDriveId) && $teamDriveId !== '' => ['teamDriveId' => $teamDriveId],
            is_string($folderId) && $folderId !== '' => ['sharedFolderId' => $folderId],
            default => [],
        };

        $root = (is_string($folderId) && $folderId !== '')
            || (is_string($teamDriveId) && $teamDriveId !== '')
            ? null
            : 'SchoolPalm Storage';

        return new GoogleDriveAdapter($drive, $root, $options);
    }

    private function dropbox(OAuthTokenState $state, array $configuration): DropboxAdapter
    {
        $client = new DropboxClient(
            new DropboxOAuthTokenProvider($state),
            maxUploadChunkRetries: 4,
        );

        $root = $configuration['root'] ?? '';

        return new DropboxAdapter($client, is_string($root) ? $root : '');
    }

    private function oneDrive(OAuthTokenState $state, array $configuration): RetryingOneDriveAdapter
    {
        $graph = new RefreshingMicrosoftGraph($state);
        $driveId = $configuration['driveId'] ?? null;

        if (! is_string($driveId) || $driveId === '') {
            $drive = $graph->createRequest('GET', '/me/drive')
                ->setReturnType(\Microsoft\Graph\Model\Drive::class)
                ->execute();
            $driveId = is_object($drive) && method_exists($drive, 'getId')
                ? $drive->getId()
                : null;
        }

        if (! is_string($driveId) || $driveId === '') {
            throw new StorageAuthorizationException('The authorized Microsoft account has no accessible OneDrive drive.');
        }

        $chunkSize = $configuration['chunkSize'] ?? (320 * 1024 * 10);

        return new RetryingOneDriveAdapter($graph, $driveId, [
            'directory_type' => 'drives',
            'chunk_size' => is_numeric($chunkSize) ? (int) $chunkSize : (320 * 1024 * 10),
        ]);
    }
}
