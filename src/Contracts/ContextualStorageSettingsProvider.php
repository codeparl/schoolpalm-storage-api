<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;

/**
 * Resolves a default or named storage connection in an explicit context.
 */
interface ContextualStorageSettingsProvider
{
    public function settingsForContext(
        ?StorageContext $context,
        ?string $connection = null,
    ): ?StorageSettings;
}
