<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

/**
 * Supplies the current tenant and school identifiers for document scoping.
 */
interface DocumentContextResolver
{
    public function tenantId(): ?string;

    public function schoolId(): ?string;
}
