<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageSettings;

interface StorageSettingsProvider
{
    public function settingsFor(?string $tenantId, ?string $schoolId): ?StorageSettings;
}
