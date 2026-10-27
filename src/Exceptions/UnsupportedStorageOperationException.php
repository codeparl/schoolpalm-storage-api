<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Exceptions;

/**
 * Indicates that the active provider cannot perform the requested operation.
 */
final class UnsupportedStorageOperationException extends StorageApiException {}
