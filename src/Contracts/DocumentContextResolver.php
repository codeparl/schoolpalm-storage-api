<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

interface DocumentContextResolver
{
    public function tenantId(): ?string;

    public function schoolId(): ?string;
}
