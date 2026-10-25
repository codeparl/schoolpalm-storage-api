<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers\Drivers;

use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;

final class LocalStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return ProviderDefinition::local();
    }

    public function toFilesystemConfig(array $configuration): array
    {
        $configuration['root'] ??= storage_path('app/private');
        $configuration['visibility'] ??= 'private';

        return parent::toFilesystemConfig($configuration);
    }
}
