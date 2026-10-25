<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use InvalidArgumentException;

final readonly class StorageContext
{
    public function __construct(
        public ?string $applicationId = null,
        public ?string $tenantId = null,
        public ?string $schoolId = null,
        public ?string $organizationId = null,
    ) {
        foreach ([$applicationId, $tenantId, $schoolId, $organizationId] as $identifier) {
            if ($identifier === '') {
                throw new InvalidArgumentException('Context identifiers must not be empty.');
            }
        }
    }

    public function cacheKey(): string
    {
        return hash('sha256', json_encode([
            $this->applicationId,
            $this->tenantId,
            $this->schoolId,
            $this->organizationId,
        ], JSON_THROW_ON_ERROR));
    }

    public function isEmpty(): bool
    {
        return $this->applicationId === null
            && $this->tenantId === null
            && $this->schoolId === null
            && $this->organizationId === null;
    }
}
