<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi;

use GuzzleHttp\Client;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Contracts\StorageOAuthCredentialStore;
use SchoolPalm\StorageApi\Contracts\StorageProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsProvider;
use SchoolPalm\StorageApi\OAuth\StorageOAuthService;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Settings\AppSettingsStorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\ConfigStorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\NullStorageContextProvider;
use SchoolPalm\StorageApi\Storage\CloudFilesystemFactory;
use SchoolPalm\StorageApi\Storage\DiskBuilder;
use SchoolPalm\StorageApi\Storage\DocumentPathResolver;
use SchoolPalm\StorageApi\Storage\LaravelDocumentStorage;

/**
 * Registers storage contracts, provider definitions, OAuth, and Laravel bindings.
 */
class StorageApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/storage-api.php', 'storage-api');

        $this->app->bindIf(StorageContextProvider::class, function ($app): StorageContextProvider {
            $provider = $app['config']->get('storage-api.context_provider');
            if ($provider === null) {
                return new NullStorageContextProvider;
            }

            $resolved = is_string($provider) ? $app->make($provider) : $provider;
            if (! $resolved instanceof StorageContextProvider) {
                throw new RuntimeException('The configured context provider must implement StorageContextProvider.');
            }

            return $resolved;
        });

        $this->app->bindIf(StorageSettingsProvider::class, function ($app): StorageSettingsProvider {
            $provider = $app['config']->get('storage-api.settings_provider');
            if ($provider === null) {
                return new ConfigStorageSettingsProvider;
            }

            if ($provider === AppSettingsStorageSettingsProvider::class) {
                return new AppSettingsStorageSettingsProvider(
                    $app->make('app-settings.manager'),
                    new ConfigStorageSettingsProvider,
                );
            }

            $resolved = is_string($provider) ? $app->make($provider) : $provider;
            if (! $resolved instanceof StorageSettingsProvider) {
                throw new RuntimeException('The configured settings provider must implement StorageSettingsProvider.');
            }

            return $resolved;
        });

        $this->app->singleton(StorageProviderRegistry::class, function ($app): StorageProviderRegistry {
            $registry = new StorageProviderRegistry;

            foreach ((array) $app['config']->get('storage-api.providers', []) as $providerClass) {
                $provider = is_string($providerClass) ? $app->make($providerClass) : $providerClass;
                if (! $provider instanceof StorageProvider) {
                    throw new RuntimeException('Every configured storage provider must implement StorageProvider.');
                }

                $registry->register($provider);
            }

            return $registry;
        });

        $this->app->scoped(DocumentPathResolver::class, function ($app): DocumentPathResolver {
            return new DocumentPathResolver($app->make(StorageContextProvider::class));
        });

        $this->app->singleton(StorageOAuthService::class, function (): StorageOAuthService {
            return new StorageOAuthService(new Client([
                'connect_timeout' => 5,
                'timeout' => 15,
            ]));
        });

        $this->app->scoped(CloudFilesystemFactory::class, function ($app): CloudFilesystemFactory {
            $store = $app['config']->get('storage-api.oauth_credential_store');
            if (is_string($store)) {
                $store = $app->make($store);
            }
            if ($store !== null && ! $store instanceof StorageOAuthCredentialStore) {
                throw new RuntimeException('The configured OAuth credential store must implement StorageOAuthCredentialStore.');
            }

            return new CloudFilesystemFactory($app->make(StorageOAuthService::class), $store);
        });

        $this->app->scoped(DiskBuilder::class, function ($app): DiskBuilder {
            return new DiskBuilder(
                $app->make(StorageSettingsProvider::class),
                $app->make(StorageProviderRegistry::class),
                $app->make(StorageFactory::class),
                $app->make(CloudFilesystemFactory::class),
            );
        });

        $this->app->scoped(LaravelDocumentStorage::class, function ($app): LaravelDocumentStorage {
            return new LaravelDocumentStorage(
                storageFactory: $app->make(StorageFactory::class),
                pathResolver: $app->make(DocumentPathResolver::class),
                diskBuilder: $app->make(DiskBuilder::class),
                fallbackDisk: (string) ($app['config']->get('storage-api.fallback_disk')
                    ?: $app['config']->get('filesystems.default', 'local')),
                providerRegistry: $app->make(StorageProviderRegistry::class),
            );
        });

        $this->app->scoped(
            DocumentStorage::class,
            fn ($app): DocumentStorage => $app->make(LaravelDocumentStorage::class),
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/storage-api.php' => config_path('storage-api.php'),
        ], 'storage-api-config');
    }
}
