<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use SchoolPalm\StorageApi\Contracts\ContextualStorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsRepository;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;

/**
 * Resolves package config and existing Laravel filesystem disks.
 */
final class ConfigStorageSettingsProvider implements ContextualStorageSettingsProvider, StorageSettingsRepository
{
    public function settingsFor(?string $tenantId, ?string $schoolId): ?StorageSettings
    {
        return $this->defaultConnection(new StorageContext(tenantId: $tenantId, schoolId: $schoolId));
    }

    public function settingsForContext(
        ?StorageContext $context,
        ?string $connection = null,
    ): ?StorageSettings {
        return $connection === null
            ? $this->defaultConnection($context)
            : $this->connection($connection, $context);
    }

    public function defaultConnection(?StorageContext $context = null): ?StorageSettings
    {
        $configuredName = (string) config('storage-api.default_connection', '');
        if ($configuredName !== '') {
            $configured = $this->connection($configuredName, $context);
            if ($configured !== null) {
                return $configured;
            }

            throw new InvalidStorageConfigurationException(sprintf(
                'Default storage connection [%s] is not configured.',
                $configuredName,
            ));
        }

        $legacyName = (string) config('document-builder.storage.disk', '');
        $diskName = $legacyName !== '' ? $legacyName : (string) config('filesystems.default', 'local');
        $diskConfig = (array) config("filesystems.disks.{$diskName}", []);

        $isFallback = $diskConfig === [];
        if ($isFallback) {
            $diskConfig = [
                'driver' => 'local',
                'root' => function_exists('storage_path') ? storage_path('app/private') : sys_get_temp_dir(),
                'throw' => true,
            ];
        }

        $provider = (string) config(
            'document-builder.storage.provider',
            $this->inferProvider($diskName, $diskConfig),
        );

        return new StorageSettings($diskName, $diskConfig, $provider, $isFallback ? 'fallback' : 'filesystem');
    }

    public function connection(string $name, ?StorageContext $context = null): ?StorageSettings
    {
        $connections = (array) config('storage-api.connections', []);
        if (isset($connections[$name]) && is_array($connections[$name])) {
            return $this->settingsFromArray($name, $connections[$name], 'config');
        }

        $diskConfig = config("filesystems.disks.{$name}");
        if (is_array($diskConfig) && $diskConfig !== []) {
            $provider = $this->inferProvider($name, $diskConfig);

            return new StorageSettings($name, $diskConfig, $provider, 'filesystem');
        }

        return null;
    }

    public function settingsFromArray(string $name, array $values, string $source = 'settings'): ?StorageSettings
    {
        if (($values['enabled'] ?? true) !== true) {
            $provider = (string) ($values['provider'] ?? '');

            return $provider === '' ? null : new StorageSettings($name, [], $provider, $source, false);
        }

        $provider = (string) ($values['provider'] ?? '');
        $configuration = $values['config'] ?? null;

        if (! is_array($configuration)) {
            $configuration = $values;
            unset($configuration['provider'], $configuration['enabled'], $configuration['name']);
        }

        if ($provider === '') {
            $provider = $this->inferProvider($name, $configuration);
        }

        return new StorageSettings($name, $configuration, $provider, $source);
    }

    public function invalidate(
        ?StorageContext $context = null,
        ?string $connection = null,
    ): void {}

    private function inferProvider(string $diskName, array $config): string
    {
        $driver = (string) ($config['driver'] ?? 'local');
        $name = strtolower($diskName);

        return match (true) {
            $driver === 's3' => 's3',
            $driver === 'local' => 'local',
            $driver === 'dropbox' => 'dropbox',
            $driver === 'onedrive' => 'onedrive',
            $driver === 'google-drive', str_contains($name, 'gdrive'), str_contains($name, 'google') => 'gdrive',
            default => $driver,
        };
    }
}
