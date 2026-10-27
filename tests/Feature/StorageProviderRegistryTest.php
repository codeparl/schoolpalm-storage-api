<?php

namespace SchoolPalm\StorageApi\Tests\Feature;

use SchoolPalm\StorageApi\Contracts\ProviderMetadataRepository;
use SchoolPalm\StorageApi\Contracts\StorageCapabilities;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;
use SchoolPalm\StorageApi\Facades\StorageApi;
use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ConfigurationField;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Providers\StorageProviderSeeder;
use SchoolPalm\StorageApi\Tests\TestCase;

/**
 * Covers provider registration, schema publication, and metadata safety.
 */
class StorageProviderRegistryTest extends TestCase
{
    public function test_builtin_provider_schemas_mask_secret_values(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);
        $metadata = $registry->definition('s3')->toArray();
        $secret = collect($metadata['fields'])->firstWhere('name', 'secret');

        $this->assertTrue($registry->has('local'));
        $this->assertTrue($registry->has('s3'));
        $this->assertTrue($secret['secret']);
        $this->assertNull($secret['default']);
        $this->assertStringNotContainsString('credential', json_encode($metadata));
    }

    public function test_s3_compatible_configuration_maps_to_laravel_s3_disk(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);
        $filesystemConfig = $registry->toFilesystemConfig('s3', [
            'key' => 'example-key',
            'secret' => 'example-secret',
            'region' => 'auto',
            'bucket' => 'private-bucket',
            'endpoint' => 'https://objects.example.test',
            'use_path_style_endpoint' => true,
        ]);

        $this->assertSame('s3', $filesystemConfig['driver']);
        $this->assertSame('https://objects.example.test', $filesystemConfig['endpoint']);
        $this->assertTrue($filesystemConfig['use_path_style_endpoint']);
        $this->assertTrue($filesystemConfig['throw']);
    }

    public function test_invalid_configuration_reports_fields_without_values(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);

        try {
            $registry->validateConfiguration('s3', [
                'key' => 'secret-value',
                'secret' => '',
                'bucket' => '',
            ]);
            $this->fail('Invalid configuration should throw.');
        } catch (InvalidStorageConfigurationException $exception) {
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
            $this->assertStringContainsString('bucket', $exception->getMessage());
        }
    }

    public function test_custom_provider_can_be_registered(): void
    {
        $registry = new StorageProviderRegistry;
        $provider = new TestStorageProvider;
        $registry->register($provider);

        $this->assertSame('test-custom', $registry->get('test-custom')->key);
        $this->assertSame(
            'test-custom',
            $registry->toFilesystemConfig('test-custom', ['endpoint' => 'https://custom.example.test'])['driver'],
        );
    }

    public function test_secret_update_preserves_omitted_secret_values(): void
    {
        $merged = $this->app->make(StorageProviderRegistry::class)
            ->definition('s3')
            ->mergePreservingSecrets(
                ['bucket' => 'new-bucket'],
                ['key' => 'stored-key', 'secret' => 'stored-secret'],
            );

        $this->assertSame('stored-key', $merged['key']);
        $this->assertSame('stored-secret', $merged['secret']);
        $this->assertSame('new-bucket', $merged['bucket']);
    }

    public function test_configuration_fields_api_exposes_registered_schemas_and_safe_defaults(): void
    {
        $registry = $this->app->make(StorageProviderRegistry::class);
        $registry->register(new SecretDefaultTestStorageProvider);

        $fields = StorageApi::providerConfigurationFields('test-secret-default');
        $secretField = StorageApi::providerConfigurationField('test-secret-default', 'apiToken');
        $fieldObjects = StorageApi::providerFieldObjects('test-secret-default');
        $allFields = StorageApi::allProviderConfigurationFields();
        $seedSettings = StorageApi::providerSeedSettings();
        $scopedSettings = StorageApi::providerScopedSettings();

        $this->assertSame('test-secret-default', $registry->definition('test-secret-default')->toArray()['key']);
        $this->assertSame('apiToken', $fields[0]['name']);
        $this->assertTrue($secretField['secret']);
        $this->assertNull($secretField['default']);
        $this->assertNull($fieldObjects[0]->default);
        $this->assertArrayHasKey('test-secret-default', $allFields);
        $this->assertArrayHasKey('gdrive', $allFields);
        $this->assertArrayHasKey('dropbox', $allFields);
        $this->assertArrayHasKey('onedrive', $allFields);
        $this->assertNull($seedSettings['test-secret-default.apiToken']);
        $this->assertNull($scopedSettings['secrets']['test-secret-default']['apiToken']);
        $this->assertNull(StorageApi::providerConfigurationField('test-secret-default', 'not-defined'));
        $this->assertStringNotContainsString('private-test-default', json_encode([
            $fields,
            $fieldObjects,
            $allFields,
            $seedSettings,
            $scopedSettings,
        ], JSON_THROW_ON_ERROR));
    }

    public function test_provider_metadata_seeding_is_repeatable_and_does_not_touch_connections(): void
    {
        $repository = new InMemoryProviderMetadataRepository;
        $repository->connections['school-a'] = ['credentials' => ['secret' => 'encrypted']];
        $seeder = new StorageProviderSeeder($this->app->make(StorageProviderRegistry::class));

        $firstCount = $seeder->seed($repository);
        $secondCount = $seeder->seed($repository);

        $this->assertSame($firstCount, $secondCount);
        $this->assertCount($firstCount, $repository->definitions);
        $this->assertSame(['credentials' => ['secret' => 'encrypted']], $repository->connections['school-a']);
    }
}

/**
 * Test provider with a deliberately sensitive schema default.
 */
final class SecretDefaultTestStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            'test-secret-default',
            'Test secret default',
            'test-secret-default',
            [ConfigurationField::string('apiToken')->default('private-test-default')->secret(true)],
            StorageCapabilities::generic('test-secret-default'),
        );
    }
}

/**
 * Minimal trusted extension used to verify custom provider registration.
 */
final class TestStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            'test-custom',
            'Test custom',
            'test-custom',
            [ConfigurationField::string('endpoint')],
            StorageCapabilities::generic('test-custom'),
        );
    }
}

/**
 * In-memory metadata repository that records only provider definitions.
 */
final class InMemoryProviderMetadataRepository implements ProviderMetadataRepository
{
    public array $definitions = [];

    public array $connections = [];

    public function upsert(ProviderDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition->toArray();
    }
}
