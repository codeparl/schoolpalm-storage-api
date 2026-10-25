<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

use SchoolPalm\StorageApi\Contracts\StorageProvider;

abstract class AbstractStorageProvider implements StorageProvider
{
    public function validateConfiguration(array $configuration): void
    {
        $this->definition()->validate($this->withDefaults($configuration));
    }

    public function toFilesystemConfig(array $configuration): array
    {
        $configuration = $this->withDefaults($configuration);
        $this->definition()->validate($configuration);

        unset($configuration['provider'], $configuration['class']);
        $configuration['driver'] = $this->definition()->driver;
        $configuration['throw'] = $configuration['throw'] ?? true;

        return $configuration;
    }

    private function withDefaults(array $configuration): array
    {
        foreach ($this->definition()->fields as $field) {
            if ((! array_key_exists($field->name, $configuration) || $configuration[$field->name] === '')
                && $field->default !== null) {
                $configuration[$field->name] = $field->default;
            }
        }

        return $configuration;
    }
}
