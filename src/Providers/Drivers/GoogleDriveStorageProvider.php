<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers\Drivers;

use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;

/**
 * Defines the Google Drive OAuth schema and built-in adapter configuration.
 */
final class GoogleDriveStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return ProviderDefinition::gdrive();
    }
}
