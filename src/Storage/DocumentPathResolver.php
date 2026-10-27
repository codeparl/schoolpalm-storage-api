<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use InvalidArgumentException;
use RuntimeException;
use SchoolPalm\StorageApi\Contracts\DocumentContextResolver;
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Settings\StorageContext;

/**
 * Validates storage-relative paths and applies context-specific prefixes.
 */
final class DocumentPathResolver
{
    private ?StorageContext $explicitContext = null;

    public function __construct(
        private readonly StorageContextProvider|DocumentContextResolver|null $contextProvider = null,
    ) {}

    public function withContext(?string $tenantId, ?string $schoolId): self
    {
        $clone = clone $this;
        $clone->explicitContext = new StorageContext(tenantId: $tenantId, schoolId: $schoolId);

        return $clone;
    }

    public function withStorageContext(StorageContext $context): self
    {
        $clone = clone $this;
        $clone->explicitContext = $context;

        return $clone;
    }

    public function resolve(string $path): string
    {
        $path = $this->normalizePath($path);
        $context = $this->currentContext();

        if (str_starts_with($path, 'secure-docs/')) {
            if ($context?->tenantId === null || $context->schoolId === null) {
                throw new RuntimeException('Secure workspace paths require tenant and school context.');
            }

            $expected = 'secure-docs/'.$this->workspaceScope($context).'/';
            if (! str_starts_with($path, $expected)) {
                throw new RuntimeException('Workspace path is outside the active tenant and school scope.');
            }

            return $path;
        }

        if ($context === null || $context->isEmpty()) {
            return $path;
        }

        $prefix = $this->scopePrefix($context);

        return $prefix === '' ? $path : $prefix.'/'.$path;
    }

    public function resolveRoot(): string
    {
        $context = $this->currentContext();
        if ($context === null || $context->isEmpty()) {
            return '';
        }

        return $this->scopePrefix($context);
    }

    public function resolveBatchPath(string $batchUuid): string
    {
        $context = $this->currentContext();
        if ($context?->tenantId === null || $context->schoolId === null) {
            throw new RuntimeException('Batch workspaces require tenant and school context.');
        }

        return 'secure-docs/'.$this->workspaceScope($context)
            .'/batches/'.$this->identifier($batchUuid);
    }

    public function currentContext(): ?StorageContext
    {
        if ($this->explicitContext !== null) {
            return $this->explicitContext;
        }

        if ($this->contextProvider instanceof StorageContextProvider) {
            return $this->contextProvider->current();
        }

        if ($this->contextProvider instanceof DocumentContextResolver) {
            $tenantId = $this->contextProvider->tenantId();
            $schoolId = $this->contextProvider->schoolId();

            return $tenantId === null && $schoolId === null
                ? null
                : new StorageContext(tenantId: $tenantId, schoolId: $schoolId);
        }

        return null;
    }

    public function currentTenantId(): ?string
    {
        return $this->currentContext()?->tenantId;
    }

    public function currentSchoolId(): ?string
    {
        return $this->currentContext()?->schoolId;
    }

    private function normalizePath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Storage path contains invalid characters.');
        }

        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:/', $path) === 1 || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Storage paths must be relative.');
        }

        $path = trim($path, '/');
        if ($path === '') {
            throw new InvalidArgumentException('Storage path must not be empty.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Storage path contains an invalid path segment.');
            }
        }

        return implode('/', $segments);
    }

    private function identifier(string $identifier): string
    {
        if ($identifier === '' || $identifier === '.' || $identifier === '..'
            || preg_match('/[\/\\\\:\x00-\x1f]/', $identifier) === 1) {
            throw new InvalidArgumentException('Storage context contains an invalid identifier.');
        }

        return $identifier;
    }

    private function scopePrefix(StorageContext $context): string
    {
        $segments = [];

        if ($context->applicationId !== null) {
            $segments[] = 'applications';
            $segments[] = $this->identifier($context->applicationId);
        }
        if ($context->organizationId !== null) {
            $segments[] = 'organizations';
            $segments[] = $this->identifier($context->organizationId);
        }
        if ($context->tenantId !== null) {
            if ($context->applicationId !== null || $context->organizationId !== null) {
                $segments[] = 'tenants';
            }
            $segments[] = $this->identifier($context->tenantId);
        }
        if ($context->schoolId !== null) {
            $segments[] = 'schools';
            $segments[] = $this->identifier($context->schoolId);
        }

        return implode('/', $segments);
    }

    private function workspaceScope(StorageContext $context): string
    {
        $segments = [];
        if ($context->applicationId !== null) {
            $segments[] = 'applications';
            $segments[] = $this->identifier($context->applicationId);
        }
        if ($context->organizationId !== null) {
            $segments[] = 'organizations';
            $segments[] = $this->identifier($context->organizationId);
        }

        $segments[] = 'tenants';
        $segments[] = $this->identifier((string) $context->tenantId);
        $segments[] = 'schools';
        $segments[] = $this->identifier((string) $context->schoolId);

        return implode('/', $segments);
    }
}
