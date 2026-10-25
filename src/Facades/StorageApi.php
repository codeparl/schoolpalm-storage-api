<?php

namespace SchoolPalm\StorageApi\Facades;

use Illuminate\Support\Facades\Facade;
use SchoolPalm\StorageApi\Contracts\DocumentStorage;

class StorageApi extends Facade
{
    public static function getFacadeRoot(): mixed
    {
        static::clearResolvedInstance(static::getFacadeAccessor());

        return parent::getFacadeRoot();
    }

    protected static function getFacadeAccessor(): string
    {
        return DocumentStorage::class;
    }
}
