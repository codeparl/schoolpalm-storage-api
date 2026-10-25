<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

interface DocumentContent
{
    public function writeTo(callable $writer): void;
}
