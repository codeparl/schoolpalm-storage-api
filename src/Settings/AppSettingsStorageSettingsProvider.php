<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use Closure;
use SchoolPalm\StorageApi\Contracts\ContextualStorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsRepository;

/**
 * Reads connections from the host App Settings manager without owning writes.
 *
 * The host remains responsible for encryption, authorization, and persistence.
 */
final class AppSettingsStorageSettingsProvider implements ContextualStorageSettingsProvider, StorageSettingsRepository
{
    private readonly ?Closure $scopeResolver;

    public function __construct(
        private readonly object $settingsManager,
        private readonly ConfigStorageSettingsProvider $fallback,
        private readonly string $group = 'storage_api',
        ?callable $scopeResolver = null,
    ) {
        $this->scopeResolver = $scopeResolver === null ? null : Closure::fromCallable($scopeResolver);
    }

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
        $values = $this->settings($context);
        $name = (string) ($values['default_connection'] ?? '');
        $connections = $values['connections'] ?? [];

        if ($name !== '' && is_array($connections) && isset($connections[$name]) && is_array($connections[$name])) {
            $settings = $this->fallback->settingsFromArray(
                $name,
                $connections[$name],
                'app-settings',
            );

            if ($settings !== null) {
                return $settings;
            }
        }

        return $this->fallback->defaultConnection();
    }

    public function connection(string $name, ?StorageContext $context = null): ?StorageSettings
    {
        $values = $this->settings($context);
        $connections = $values['connections'] ?? [];
        if (! is_array($connections) || ! isset($connections[$name]) || ! is_array($connections[$name])) {
            return $this->fallback->connection($name);
        }

        return $this->fallback->settingsFromArray($name, $connections[$name], 'app-settings');
    }

    public function invalidate(
        ?StorageContext $context = null,
        ?string $connection = null,
    ): void {
        $this->fallback->invalidate($context, $connection);
    }

    private function settings(?StorageContext $context): array
    {
        if ($this->scopeResolver !== null) {
            $builder = ($this->scopeResolver)($this->settingsManager, $context, $this->group);
        } else {
            $builder = $this->settingsManager;
            if ($context?->tenantId !== null && $context->schoolId !== null) {
                $builder = $builder->context('tenant', $context->tenantId)
                    ->group($this->group.'.schools.'.$context->schoolId);
            } elseif ($context?->schoolId !== null) {
                $builder = $builder->context('school', $context->schoolId)->group($this->group);
            } elseif ($context?->tenantId !== null) {
                $builder = $builder->context('tenant', $context->tenantId)->group($this->group);
            } elseif ($context?->organizationId !== null) {
                $builder = $builder->context('organization', $context->organizationId)->group($this->group);
            } elseif ($context?->applicationId !== null) {
                $builder = $builder->context('application', $context->applicationId)->group($this->group);
            } else {
                $builder = $builder->group($this->group);
            }
        }

        $values = $builder->get();

        return is_array($values) ? $values : [];
    }
}
