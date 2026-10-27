<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageContext;

/**
 * Resolves the active application context without coupling to host models.
 */
interface StorageContextProvider
{
    public function current(): ?StorageContext;
}
