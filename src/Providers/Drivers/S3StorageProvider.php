<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers\Drivers;

use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;

final class S3StorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return ProviderDefinition::s3();
    }

    public function toFilesystemConfig(array $configuration): array
    {
        $configuration['visibility'] ??= 'private';
        foreach (['key', 'secret', 'endpoint', 'url', 'prefix'] as $key) {
            if (($configuration[$key] ?? null) === '') {
                unset($configuration[$key]);
            }
        }

        return parent::toFilesystemConfig($configuration);
    }
}
