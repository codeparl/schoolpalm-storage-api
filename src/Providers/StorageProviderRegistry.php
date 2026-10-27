<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

use InvalidArgumentException;
use SchoolPalm\StorageApi\Contracts\StorageProvider;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageProviderException;
use SchoolPalm\StorageApi\Providers\Drivers\DropboxStorageProvider;
use SchoolPalm\StorageApi\Providers\Drivers\GoogleDriveStorageProvider;
use SchoolPalm\StorageApi\Providers\Drivers\LocalStorageProvider;
use SchoolPalm\StorageApi\Providers\Drivers\OneDriveStorageProvider;
use SchoolPalm\StorageApi\Providers\Drivers\S3StorageProvider;

/**
 * Owns the trusted provider implementations and their stable definitions.
 *
 * School settings select a registered ID; they cannot register executable
 * classes or drivers dynamically.
 */
final class StorageProviderRegistry
{
    /** @var array<string, StorageProvider> */
    private array $providers = [];

    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    public function __construct(?iterable $providers = null)
    {
        $providers ??= [
            new LocalStorageProvider,
            new S3StorageProvider,
            new GoogleDriveStorageProvider,
            new DropboxStorageProvider,
            new OneDriveStorageProvider,
        ];

        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(StorageProvider|ProviderDefinition $provider, bool $replace = false): void
    {
        $definition = $provider instanceof StorageProvider ? $provider->definition() : $provider;
        $key = $definition->key;

        if ($key === '') {
            throw new InvalidArgumentException('Storage provider identifiers must not be empty.');
        }

        if (isset($this->definitions[$key]) && ! $replace) {
            throw new InvalidArgumentException(sprintf('Storage provider [%s] is already registered.', $key));
        }

        $this->definitions[$key] = $definition;
        if ($provider instanceof StorageProvider) {
            $this->providers[$key] = $provider;
        } else {
            unset($this->providers[$key]);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): ProviderDefinition
    {
        return $this->definition($key);
    }

    public function provider(string $key): StorageProvider
    {
        if (! isset($this->providers[$key])) {
            throw new UnsupportedStorageProviderException('The requested storage provider is unavailable.');
        }

        return $this->providers[$key];
    }

    public function definition(string $key): ProviderDefinition
    {
        if (! isset($this->definitions[$key])) {
            throw new UnsupportedStorageProviderException('The requested storage provider is not registered.');
        }

        return $this->definitions[$key];
    }

    /**
     * @return array<string, ProviderDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->definitions as $key => $definition) {
            $options[$key] = $definition->label;
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(
            static fn (ProviderDefinition $definition): array => $definition->toArray(),
            $this->definitions,
        ));
    }

    public function validateConfiguration(string $provider, array $configuration): void
    {
        $this->provider($provider)->validateConfiguration($configuration);
    }

    public function toFilesystemConfig(string $provider, array $configuration): array
    {
        return $this->provider($provider)->toFilesystemConfig($configuration);
    }
}
