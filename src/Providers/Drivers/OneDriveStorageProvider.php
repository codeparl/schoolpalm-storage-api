<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers\Drivers;

use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;

/**
 * Defines the Microsoft OneDrive OAuth schema and built-in adapter config.
 */
final class OneDriveStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return ProviderDefinition::onedrive();
    }
}
