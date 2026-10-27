<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Providers\ProviderDefinition;

/**
 * Trusted provider implementation that validates config and builds disk config.
 */
interface StorageProvider
{
    public function definition(): ProviderDefinition;

    public function validateConfiguration(array $configuration): void;

    public function toFilesystemConfig(array $configuration): array;
}
