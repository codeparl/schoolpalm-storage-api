<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageSettings;

/**
 * Resolves the active storage connection without prescribing persistence.
 */
interface StorageSettingsProvider
{
    public function settingsFor(?string $tenantId, ?string $schoolId): ?StorageSettings;
}
