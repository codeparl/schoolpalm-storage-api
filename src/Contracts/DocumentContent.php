<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

/**
 * Streams or writes a document payload through a caller-provided writer.
 */
interface DocumentContent
{
    public function writeTo(callable $writer): void;
}
