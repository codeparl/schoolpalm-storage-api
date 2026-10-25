<?php

namespace SchoolPalm\StorageApi\Exceptions;

final class InvalidStorageConfigurationException extends StorageApiException
{
    public static function forFields(string $provider, array $fields): self
    {
        return new self(sprintf(
            'Storage configuration is invalid; check fields: %s.',
            implode(', ', $fields),
        ));
    }
}
