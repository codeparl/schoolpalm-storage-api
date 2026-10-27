<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

/**
 * Exposes registered provider schemas for host-owned settings forms and setup.
 *
 * This helper reads exclusively from StorageProviderRegistry. It neither
 * persists settings nor represents a configured school connection.
 */
final class ProviderConfigurationFields
{
    public function __construct(
        private readonly StorageProviderRegistry $registry,
    ) {}

    /**
     * Return one provider's configuration schema as safe metadata arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function providerConfigurationFields(string $provider): array
    {
        return $this->registry->definition($provider)->toArray()['fields'];
    }

    /**
     * Return one provider's schema as field value objects safe for metadata use.
     *
     * Secret defaults are removed from these copies.
     *
     * @return list<ConfigurationField>
     */
    public function providerFieldObjects(string $provider): array
    {
        return array_map(
            static fn (ConfigurationField $field): ConfigurationField => $field->metadataCopy(),
            $this->registry->definition($provider)->configurationFields(),
        );
    }

    /**
     * Return field schemas for every registered provider, keyed by stable ID.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function allProviderConfigurationFields(): array
    {
        $fields = [];

        foreach ($this->registry->all() as $provider => $definition) {
            $fields[$provider] = $definition->toArray()['fields'];
        }

        return $fields;
    }

    /**
     * Find one field schema; return null when the provider has no such field.
     *
     * Unknown provider IDs retain the registry's normal exception behavior.
     *
     * @return array<string, mixed>|null
     */
    public function providerConfigurationField(string $provider, string $field): ?array
    {
        foreach ($this->providerConfigurationFields($provider) as $metadata) {
            if ($metadata['name'] === $field) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * Return default schema values keyed as "provider.field".
     *
     * These are metadata defaults only, not school connection settings. Secret
     * field values are always null in the serialized schema.
     *
     * @return array<string, mixed>
     */
    public function providerSeedSettings(): array
    {
        $settings = [];

        foreach ($this->allProviderConfigurationFields() as $provider => $fields) {
            foreach ($fields as $field) {
                $settings[$provider.'.'.$field['name']] = $field['default'];
            }
        }

        return $settings;
    }

    /**
     * Return safe defaults split into ordinary and secret field groups.
     *
     * The returned values are schema defaults, not persisted credentials.
     *
     * @return array{secured: array<string, array<string, mixed>>, secrets: array<string, array<string, mixed>>}
     */
    public function providerScopedSettings(): array
    {
        $settings = [
            'secured' => [],
            'secrets' => [],
        ];

        foreach ($this->allProviderConfigurationFields() as $provider => $fields) {
            foreach ($fields as $field) {
                $scope = $field['secret'] ? 'secrets' : 'secured';
                $settings[$scope][$provider][$field['name']] = $field['default'];
            }
        }

        return $settings;
    }
}
