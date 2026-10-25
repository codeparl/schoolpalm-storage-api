<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Providers\ProviderDefinition;

interface ProviderMetadataRepository
{
    /**
     * Upsert a definition by its stable provider key without altering
     * saved connection values or school-owned metadata.
     */
    public function upsert(ProviderDefinition $definition): void;
}
