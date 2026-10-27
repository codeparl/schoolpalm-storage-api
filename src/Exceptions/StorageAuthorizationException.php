<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Exceptions;

/**
 * Indicates missing, expired, or revoked provider authorization.
 */
final class StorageAuthorizationException extends StorageApiException {}
