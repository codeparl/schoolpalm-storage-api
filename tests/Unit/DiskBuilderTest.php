<?php

namespace SchoolPalm\StorageApi\Tests\Unit;

use Illuminate\Contracts\Filesystem\Factory;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use SchoolPalm\StorageApi\Contracts\ContextualStorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsProvider;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageProviderException;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;
use SchoolPalm\StorageApi\Storage\DiskBuilder;
use SchoolPalm\StorageApi\Tests\TestCase;

/**
 * Verifies disk construction, context isolation, and scoped cache behavior.
 */
class DiskBuilderTest extends TestCase
{
    public function test_disk_cache_is_scoped_by_context_and_rebuilt_when_settings_change(): void
    {
        $firstRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage-api-first-'.uniqid('', true);
        $secondRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage-api-second-'.uniqid('', true);
        mkdir($firstRoot);
        mkdir($secondRoot);

        try {
            $settings = new MutableContextSettingsProvider($firstRoot);
            $builder = new DiskBuilder(
                $settings,
                new StorageProviderRegistry,
                $this->app->make(Factory::class),
            );
            $firstContext = new StorageContext(tenantId: 'tenant-a', schoolId: 'school-a');
            $secondContext = new StorageContext(tenantId: 'tenant-b', schoolId: 'school-a');

            $firstDisk = $builder->forContext('tenant-a', 'school-a', null, $firstContext);
            $otherSchoolDisk = $builder->forContext('tenant-b', 'school-a', null, $secondContext);
            $this->assertNotSame($firstDisk, $otherSchoolDisk);

            $settings->root = $secondRoot;
            $updatedDisk = $builder->forContext('tenant-a', 'school-a', null, $firstContext);
            $this->assertNotSame($firstDisk, $updatedDisk);
        } finally {
            @rmdir($firstRoot);
            @rmdir($secondRoot);
        }
    }

    public function test_invalidation_only_clears_the_requested_context_and_connection(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage-api-invalidation-'.uniqid('', true);
        mkdir($root);

        try {
            $builder = new DiskBuilder(
                new MutableContextSettingsProvider($root),
                new StorageProviderRegistry,
                $this->app->make(Factory::class),
            );
            $firstContext = new StorageContext(tenantId: 'tenant-a', schoolId: 'school-a');
            $secondContext = new StorageContext(tenantId: 'tenant-b', schoolId: 'school-a');
            $firstDisk = $builder->forContext('tenant-a', 'school-a', 'school-files', $firstContext);
            $secondDisk = $builder->forContext('tenant-b', 'school-a', 'school-files', $secondContext);

            $builder->invalidate($firstContext, 'school-files');

            $this->assertNotSame(
                $firstDisk,
                $builder->forContext('tenant-a', 'school-a', 'school-files', $firstContext),
            );
            $this->assertSame(
                $secondDisk,
                $builder->forContext('tenant-b', 'school-a', 'school-files', $secondContext),
            );
        } finally {
            @rmdir($root);
        }
    }

    public function test_missing_s3_adapter_has_an_actionable_error(): void
    {
        if (class_exists(AwsS3V3Adapter::class)) {
            $this->assertTrue(true);

            return;
        }

        $builder = new DiskBuilder(
            new MutableContextSettingsProvider(sys_get_temp_dir()),
            new StorageProviderRegistry,
            $this->app->make(Factory::class),
        );

        $this->expectException(UnsupportedStorageProviderException::class);
        $this->expectExceptionMessage('league/flysystem-aws-s3-v3');
        $builder->build(new StorageSettings(
            'private-s3',
            ['region' => 'us-east-1', 'bucket' => 'private-bucket'],
            's3',
        ));
    }
}

/**
 * Mutable context-aware source used to test scoped disk rebuilding.
 */
final class MutableContextSettingsProvider implements ContextualStorageSettingsProvider, StorageSettingsProvider
{
    public function __construct(public string $root) {}

    public function settingsFor(?string $tenantId, ?string $schoolId): ?StorageSettings
    {
        return $this->settingsForContext(new StorageContext(tenantId: $tenantId, schoolId: $schoolId));
    }

    public function settingsForContext(
        ?StorageContext $context,
        ?string $connection = null,
    ): ?StorageSettings {
        return new StorageSettings(
            $connection ?? 'school-files',
            ['driver' => 'local', 'root' => $this->root],
            'local',
            'test',
        );
    }
}
