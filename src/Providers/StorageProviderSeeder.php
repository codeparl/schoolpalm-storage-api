<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

use SchoolPalm\StorageApi\Contracts\ProviderMetadataRepository;

final class StorageProviderSeeder
{
    public function __construct(private readonly StorageProviderRegistry $registry) {}

    public function seed(ProviderMetadataRepository $repository): int
    {
        foreach ($this->registry->all() as $definition) {
            $repository->upsert($definition);
        }

        return count($this->registry->all());
    }
}
