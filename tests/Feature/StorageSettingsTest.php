<?php

namespace SchoolPalm\StorageApi\Tests\Feature;

use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageOperationException;
use SchoolPalm\StorageApi\Settings\AppSettingsStorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\ConfigStorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;
use SchoolPalm\StorageApi\Tests\TestCase;

class StorageSettingsTest extends TestCase
{
    public function test_config_provider_uses_application_default_and_named_connections(): void
    {
        config()->set('storage-api.default_connection', 'archive');
        config()->set('storage-api.connections.archive', [
            'provider' => 'local',
            'config' => ['root' => sys_get_temp_dir()],
        ]);

        $settings = (new ConfigStorageSettingsProvider)->defaultConnection();
        $named = (new ConfigStorageSettingsProvider)->connection('archive');

        $this->assertSame('archive', $settings->diskName);
        $this->assertSame('archive', $named->diskName);
        $this->assertSame('local', $named->provider);
    }

    public function test_app_settings_adapter_scopes_school_connections(): void
    {
        $manager = new FakeSettingsManager([
            'tenant:tenant-a:storage_api.schools.school-a' => [
                'default_connection' => 'school-files',
                'connections' => [
                    'school-files' => [
                        'provider' => 'local',
                        'config' => ['root' => sys_get_temp_dir()],
                    ],
                ],
            ],
            'tenant:tenant-b:storage_api.schools.school-a' => [
                'default_connection' => 'other-school-files',
                'connections' => [
                    'other-school-files' => [
                        'provider' => 'local',
                        'config' => ['root' => sys_get_temp_dir()],
                    ],
                ],
            ],
        ]);
        $provider = new AppSettingsStorageSettingsProvider($manager, new ConfigStorageSettingsProvider);

        $first = $provider->defaultConnection(new StorageContext(tenantId: 'tenant-a', schoolId: 'school-a'));
        $second = $provider->defaultConnection(new StorageContext(tenantId: 'tenant-b', schoolId: 'school-a'));

        $this->assertSame('school-files', $first->diskName);
        $this->assertSame('other-school-files', $second->diskName);
        $this->assertSame('app-settings', $first->source);
    }

    public function test_private_s3_connections_do_not_expose_public_urls(): void
    {
        config()->set('storage-api.connections.private-objects', [
            'provider' => 's3',
            'config' => [
                'region' => 'us-east-1',
                'bucket' => 'private-bucket',
                'visibility' => 'private',
            ],
        ]);

        $storage = $this->app->make(DocumentStorage::class)->forConnection('private-objects');

        $this->expectException(UnsupportedStorageOperationException::class);
        $storage->publicUrl('private-file.txt');
    }

    public function test_missing_named_connection_does_not_fall_back_to_default(): void
    {
        config()->set('filesystems.default', 'local');
        $storage = $this->app->make(DocumentStorage::class)->forConnection('missing-connection');

        $this->expectException(InvalidStorageConfigurationException::class);
        $storage->get('report.csv');
    }

    public function test_configured_laravel_filesystem_disks_are_used_directly(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage-api-filesystem-'.uniqid('', true);
        mkdir($root);
        config()->set('filesystems.disks.package-test', [
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
        ]);

        try {
            $storage = $this->app->make(DocumentStorage::class)->forConnection('package-test');
            $storage->put('probe.txt', 'filesystem disk');

            $this->assertSame('filesystem disk', file_get_contents($root.DIRECTORY_SEPARATOR.'probe.txt'));
        } finally {
            if (is_file($root.DIRECTORY_SEPARATOR.'probe.txt')) {
                unlink($root.DIRECTORY_SEPARATOR.'probe.txt');
            }

            rmdir($root);
        }
    }

    public function test_storage_settings_json_never_exposes_secret_configuration_values(): void
    {
        $settings = new StorageSettings(
            'private',
            [
                'driver' => 's3',
                'key' => 'not-for-display',
                'secret' => 'also-not-for-display',
                'aws_access_key_id' => 'access-key-not-for-display',
                'privateKey' => 'private-key-not-for-display',
                'nested' => ['refreshToken' => 'another-secret'],
                'client' => (object) ['token' => 'object-secret'],
            ],
            's3',
        );

        $serialized = json_encode($settings, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('not-for-display', $serialized);
        $this->assertStringNotContainsString('also-not-for-display', $serialized);
        $this->assertStringNotContainsString('access-key-not-for-display', $serialized);
        $this->assertStringNotContainsString('private-key-not-for-display', $serialized);
        $this->assertStringNotContainsString('another-secret', $serialized);
        $this->assertStringNotContainsString('object-secret', $serialized);
        $this->assertStringContainsString('********', $serialized);
    }
}

final class FakeSettingsManager
{
    private ?string $contextType = null;

    private string|int|null $contextId = null;

    private ?string $group = null;

    public function __construct(private readonly array $values) {}

    public function context(string $type, string|int|null $identifier = null): self
    {
        $clone = clone $this;
        $clone->contextType = $type;
        $clone->contextId = $identifier;

        return $clone;
    }

    public function group(string $group): self
    {
        $clone = clone $this;
        $clone->group = $group;

        return $clone;
    }

    public function get(): mixed
    {
        return $this->values[sprintf(
            '%s:%s:%s',
            $this->contextType ?? 'global',
            $this->contextId ?? '',
            $this->group ?? '',
        )] ?? [];
    }
}
