<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use SchoolPalm\StorageApi\Contracts\StorageContextProvider;

/**
 * Returns no context for standalone applications without a host resolver.
 */
final class NullStorageContextProvider implements StorageContextProvider
{
    public function current(): ?StorageContext
    {
        return null;
    }
}
