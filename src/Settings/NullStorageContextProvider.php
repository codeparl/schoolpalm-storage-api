<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use SchoolPalm\StorageApi\Contracts\StorageContextProvider;

final class NullStorageContextProvider implements StorageContextProvider
{
    public function current(): ?StorageContext
    {
        return null;
    }
}
