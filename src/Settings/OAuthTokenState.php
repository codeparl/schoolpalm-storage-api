<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use SchoolPalm\StorageApi\Contracts\StorageOAuthCredentialStore;
use SchoolPalm\StorageApi\Exceptions\StorageAuthorizationException;
use SchoolPalm\StorageApi\OAuth\StorageOAuthService;

/**
 * Resolves, refreshes, and persists OAuth tokens for one scoped connection.
 */
final class OAuthTokenState
{
    private array $configuration;

    public function __construct(
        private readonly string $provider,
        array $configuration,
        private readonly ?StorageContext $context,
        private readonly string $connection,
        private readonly StorageOAuthService $oauth,
        private readonly ?StorageOAuthCredentialStore $credentialStore,
    ) {
        $this->configuration = $configuration;
    }

    public function configuration(): array
    {
        $this->refreshIfRequired();

        return $this->configuration;
    }

    public function accessToken(): string
    {
        $this->refreshIfRequired();
        $token = $this->configuration['accessToken'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new StorageAuthorizationException('Storage authorization is missing; reconnect this account.');
        }

        return $token;
    }

    public function forceRefresh(): bool
    {
        $refreshToken = $this->configuration['refreshToken'] ?? null;
        if (! is_string($refreshToken) || $refreshToken === '') {
            return false;
        }

        $updated = $this->oauth->refresh($this->provider, $this->configuration);
        if ($this->credentialStore === null) {
            throw new StorageAuthorizationException(
                'Bind StorageOAuthCredentialStore to securely persist refreshed storage credentials.',
            );
        }

        $credentials = [
            'accessToken' => $updated['accessToken'],
            'accessTokenExpiresAt' => $updated['accessTokenExpiresAt'],
        ];
        $newRefreshToken = $updated['refreshToken'] ?? $this->configuration['refreshToken'] ?? '';
        if ($newRefreshToken !== '') {
            $credentials['refreshToken'] = $newRefreshToken;
        }
        $this->credentialStore->save($this->context, $this->connection, $this->provider, $credentials);
        $this->configuration = array_replace($this->configuration, $credentials);

        return true;
    }

    public function persistAccessToken(string $accessToken, int $expiresIn = 3600): void
    {
        if ($accessToken === '') {
            throw new StorageAuthorizationException('The storage provider returned an empty access token.');
        }

        if ($this->credentialStore === null) {
            throw new StorageAuthorizationException(
                'Bind StorageOAuthCredentialStore to securely persist refreshed storage credentials.',
            );
        }

        $credentials = [
            'accessToken' => $accessToken,
            'accessTokenExpiresAt' => gmdate(DATE_ATOM, time() + max(0, $expiresIn)),
        ];
        $refreshToken = $this->configuration['refreshToken'] ?? null;
        if (is_string($refreshToken) && $refreshToken !== '') {
            $credentials['refreshToken'] = $refreshToken;
        }

        $this->credentialStore->save($this->context, $this->connection, $this->provider, $credentials);
        $this->configuration = array_replace($this->configuration, $credentials);
    }

    private function refreshIfRequired(): void
    {
        $accessToken = $this->configuration['accessToken'] ?? null;
        $refreshToken = $this->configuration['refreshToken'] ?? null;
        $expiresAt = $this->configuration['accessTokenExpiresAt'] ?? null;
        $expiry = is_string($expiresAt) ? strtotime($expiresAt) : false;
        $needsRefresh = ! is_string($accessToken)
            || $accessToken === ''
            || (is_string($refreshToken) && $refreshToken !== ''
                && ($expiry === false || $expiry <= time() + 120));

        if (! $needsRefresh) {
            return;
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new StorageAuthorizationException('Storage authorization is missing or expired; reconnect this account.');
        }

        $this->forceRefresh();
    }
}
