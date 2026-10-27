<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use SchoolPalm\StorageApi\Contracts\ContextualStorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsRepository;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;
use SchoolPalm\StorageApi\Exceptions\StorageApiException;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageProviderException;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;

/**
 * Resolves scoped settings into cached Laravel filesystem adapters.
 */
final class DiskBuilder
{
    /** @var array<string, array{signature: string, disk: FilesystemAdapter, context: string, connection: string}> */
    private array $cache = [];

    private readonly string $signatureKey;

    private readonly StorageProviderRegistry $providers;

    public function __construct(
        private readonly StorageSettingsProvider $settings,
        ?StorageProviderRegistry $providers = null,
        private readonly ?StorageFactory $storageFactory = null,
        private readonly ?CloudFilesystemFactory $cloudFilesystemFactory = null,
    ) {
        $this->signatureKey = random_bytes(32);
        $this->providers = $providers ?? new StorageProviderRegistry;
    }

    public function forContext(
        ?string $tenantId,
        ?string $schoolId,
        ?string $connection = null,
        ?StorageContext $context = null,
    ): ?FilesystemAdapter {
        $context ??= new StorageContext(tenantId: $tenantId, schoolId: $schoolId);
        $settings = $this->settingsFor($tenantId, $schoolId, $connection, $context);

        return $settings === null ? null : $this->build($settings, $context);
    }

    public function build(StorageSettings $settings, ?StorageContext $context = null): FilesystemAdapter
    {
        if (! $settings->enabled) {
            throw new InvalidStorageConfigurationException(sprintf(
                'Storage connection [%s] is disabled.',
                $settings->diskName,
            ));
        }

        if ($settings->provider === 's3'
            && ! class_exists(AwsS3V3Adapter::class)) {
            throw new UnsupportedStorageProviderException(
                'The S3 filesystem adapter is missing; install league/flysystem-aws-s3-v3.',
            );
        }

        $cacheContext = $context?->cacheKey() ?? 'application';
        $key = hash('sha256', $cacheContext.'|'.$settings->diskName);
        $signature = hash_hmac('sha256', serialize([$settings->provider, $settings->config]), $this->signatureKey);

        if (isset($this->cache[$key]) && hash_equals($this->cache[$key]['signature'], $signature)) {
            return $this->cache[$key]['disk'];
        }

        unset($this->cache[$key]);

        try {
            if (in_array($settings->provider, ['gdrive', 'dropbox', 'onedrive'], true)) {
                if ($this->cloudFilesystemFactory === null) {
                    throw new UnsupportedStorageProviderException(
                        'The built-in cloud storage integration is not available in this application.',
                    );
                }

                $disk = $this->cloudFilesystemFactory->build(
                    $settings->provider,
                    $settings->config,
                    $context,
                    $settings->diskName,
                );
            } elseif ($settings->source === 'filesystem') {
                $filesystemConfig = $settings->config;
                if ($this->storageFactory !== null) {
                    if (method_exists($this->storageFactory, 'forgetDisk')) {
                        $this->storageFactory->forgetDisk($settings->diskName);
                    }

                    $disk = $this->storageFactory->disk($settings->diskName);
                } else {
                    Storage::forgetDisk($settings->diskName);
                    $disk = Storage::disk($settings->diskName);
                }
            } else {
                $filesystemConfig = $this->providers->provider($settings->provider)->toFilesystemConfig($settings->config);
                $disk = $this->storageFactory?->build($filesystemConfig)
                    ?? Storage::build($filesystemConfig);
            }
        } catch (StorageApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new StorageApiException('The configured storage connection could not be created.');
        }

        if (! $disk instanceof FilesystemAdapter) {
            throw new StorageApiException('The configured storage connection returned an unsupported filesystem.');
        }

        $this->cache[$key] = [
            'signature' => $signature,
            'disk' => $disk,
            'context' => $cacheContext,
            'connection' => $settings->diskName,
        ];

        return $disk;
    }

    public function settingsFor(
        ?string $tenantId,
        ?string $schoolId,
        ?string $connection = null,
        ?StorageContext $context = null,
    ): ?StorageSettings {
        $context ??= new StorageContext(tenantId: $tenantId, schoolId: $schoolId);

        if ($this->settings instanceof ContextualStorageSettingsProvider) {
            return $this->settings->settingsForContext($context, $connection);
        }

        if ($connection !== null) {
            if ($this->settings instanceof StorageSettingsRepository) {
                return $this->settings->connection($connection, $context);
            }

            throw new UnsupportedStorageProviderException(
                'The configured settings provider does not support named connections.',
            );
        }

        return $this->settings->settingsFor($tenantId, $schoolId);
    }

    public function invalidate(
        ?StorageContext $context = null,
        ?string $connection = null,
    ): void {
        if ($this->settings instanceof StorageSettingsRepository) {
            $this->settings->invalidate($context, $connection);
        }

        if ($context === null && $connection === null) {
            $this->cache = [];

            return;
        }

        $contextKey = $context?->cacheKey() ?? 'application';
        foreach (array_keys($this->cache) as $key) {
            $entry = $this->cache[$key];
            if (($connection === null || $entry['connection'] === $connection)
                && ($context === null || $entry['context'] === $contextKey)) {
                unset($this->cache[$key]);
            }
        }
    }
}
