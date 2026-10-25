<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageContext;

interface StorageContextProvider
{
    public function current(): ?StorageContext;
}
