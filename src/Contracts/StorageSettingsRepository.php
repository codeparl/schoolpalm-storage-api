<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;

/**
 * Reads and invalidates host-owned default and named connection settings.
 */
interface StorageSettingsRepository extends StorageSettingsProvider
{
    public function defaultConnection(?StorageContext $context = null): ?StorageSettings;

    public function connection(string $name, ?StorageContext $context = null): ?StorageSettings;

    public function invalidate(
        ?StorageContext $context = null,
        ?string $connection = null,
    ): void;
}
