<?php

namespace SchoolPalm\StorageApi\Facades;

use Illuminate\Support\Facades\Facade;
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Providers\ConfigurationField;
use SchoolPalm\StorageApi\Providers\ProviderConfigurationFields;

/**
 * Facade for document operations and read-only provider schema metadata.
 */
class StorageApi extends Facade
{
    /**
     * Get one registered provider's fields as safe metadata arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function providerConfigurationFields(string $provider): array
    {
        return static::configurationFields()->providerConfigurationFields($provider);
    }

    /**
     * Get one provider's field objects with secret defaults removed.
     *
     * @return list<ConfigurationField>
     */
    public static function providerFieldObjects(string $provider): array
    {
        return static::configurationFields()->providerFieldObjects($provider);
    }

    /**
     * Get every registered provider's fields keyed by stable provider ID.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function allProviderConfigurationFields(): array
    {
        return static::configurationFields()->allProviderConfigurationFields();
    }

    /**
     * Find one provider field, or null if that field is not defined.
     *
     * @return array<string, mixed>|null
     */
    public static function providerConfigurationField(string $provider, string $field): ?array
    {
        return static::configurationFields()->providerConfigurationField($provider, $field);
    }

    /**
     * Get safe schema defaults keyed as "provider.field".
     *
     * @return array<string, mixed>
     */
    public static function providerSeedSettings(): array
    {
        return static::configurationFields()->providerSeedSettings();
    }

    /**
     * Get safe schema defaults grouped into ordinary and secret fields.
     *
     * @return array{secured: array<string, array<string, mixed>>, secrets: array<string, array<string, mixed>>}
     */
    public static function providerScopedSettings(): array
    {
        return static::configurationFields()->providerScopedSettings();
    }

    private static function configurationFields(): ProviderConfigurationFields
    {
        return app(ProviderConfigurationFields::class);
    }

    public static function getFacadeRoot(): mixed
    {
        static::clearResolvedInstance(static::getFacadeAccessor());

        return parent::getFacadeRoot();
    }

    protected static function getFacadeAccessor(): string
    {
        return DocumentStorage::class;
    }
}
