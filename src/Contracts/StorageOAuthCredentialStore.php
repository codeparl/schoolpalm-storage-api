<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use SchoolPalm\StorageApi\Settings\StorageContext;

interface StorageOAuthCredentialStore
{
    /**
     * Persist OAuth credentials in the existing host settings store, encrypted at rest.
     *
     * @param  array{accessToken: string, refreshToken?: string, accessTokenExpiresAt: string}  $credentials
     */
    public function save(
        ?StorageContext $context,
        string $connection,
        string $provider,
        array $credentials,
    ): void;
}
