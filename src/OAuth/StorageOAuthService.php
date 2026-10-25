<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\OAuth;

use GuzzleHttp\ClientInterface;
use SchoolPalm\StorageApi\Exceptions\StorageAuthorizationException;
use Throwable;

final class StorageOAuthService
{
    private const SCOPES = [
        'gdrive' => ['https://www.googleapis.com/auth/drive.file'],
        'dropbox' => [
            'files.content.read',
            'files.content.write',
            'files.metadata.read',
            'files.metadata.write',
        ],
        'onedrive' => ['offline_access', 'Files.ReadWrite', 'User.Read'],
    ];

    public function __construct(private readonly ClientInterface $http) {}

    public function authorizationUrl(
        string $provider,
        array $configuration,
        string $redirectUri,
        string $state,
        ?string $codeVerifier = null,
    ): string {
        $this->assertProvider($provider);
        $this->requireClientId($configuration);
        if ($redirectUri === '' || $state === '') {
            throw new StorageAuthorizationException('OAuth redirect URI and state are required.');
        }
        $this->validateCodeVerifier($codeVerifier);

        $parameters = [
            'client_id' => $configuration['clientId'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES[$provider]),
            'state' => $state,
        ];
        $endpoint = match ($provider) {
            'gdrive' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'dropbox' => 'https://www.dropbox.com/oauth2/authorize',
            'onedrive' => sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize',
                rawurlencode((string) ($configuration['tenantId'] ?? 'common')),
            ),
        };

        if ($provider === 'gdrive') {
            $parameters['access_type'] = 'offline';
            $parameters['include_granted_scopes'] = 'true';
            $parameters['prompt'] = 'consent';
        } elseif ($provider === 'dropbox') {
            $parameters['token_access_type'] = 'offline';
        }
        if ($codeVerifier !== null) {
            $parameters['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            $parameters['code_challenge_method'] = 'S256';
        }

        return $endpoint.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(
        string $provider,
        array $configuration,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array {
        if ($code === '' || $redirectUri === '') {
            throw new StorageAuthorizationException('OAuth authorization code and redirect URI are required.');
        }
        $this->validateCodeVerifier($codeVerifier);

        $grant = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        if ($codeVerifier !== null) {
            $grant['code_verifier'] = $codeVerifier;
        }

        return $this->requestToken($provider, $configuration, $grant);
    }

    public function refresh(string $provider, array $configuration): array
    {
        $refreshToken = $configuration['refreshToken'] ?? null;
        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new StorageAuthorizationException('Storage authorization has expired; reconnect this account.');
        }

        return $this->requestToken($provider, $configuration, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function requestToken(string $provider, array $configuration, array $grant): array
    {
        $this->assertProvider($provider);
        $this->requireClientId($configuration);
        $clientSecret = $configuration['clientSecret'] ?? null;
        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new StorageAuthorizationException('OAuth client secret is missing; update the storage connection.');
        }

        $endpoint = match ($provider) {
            'gdrive' => 'https://oauth2.googleapis.com/token',
            'dropbox' => 'https://api.dropbox.com/oauth2/token',
            'onedrive' => sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
                rawurlencode((string) ($configuration['tenantId'] ?? 'common')),
            ),
        };

        try {
            $response = $this->http->request('POST', $endpoint, [
                'form_params' => array_merge($grant, [
                    'client_id' => $configuration['clientId'],
                    'client_secret' => $clientSecret,
                ]),
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
            ]);
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageAuthorizationException(
                'Storage authorization failed or was revoked; reconnect this account and verify its OAuth permissions.',
            );
        }

        if (! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new StorageAuthorizationException('The storage provider returned an invalid authorization response.');
        }

        $expiresIn = filter_var($body['expires_in'] ?? null, FILTER_VALIDATE_INT);
        $credentials = [
            'accessToken' => $body['access_token'],
            'refreshToken' => is_string($body['refresh_token'] ?? null)
                ? $body['refresh_token']
                : (string) ($configuration['refreshToken'] ?? ''),
            'accessTokenExpiresAt' => gmdate(DATE_ATOM, time() + max(0, $expiresIn === false ? 3600 : $expiresIn)),
        ];

        if ($credentials['refreshToken'] === '') {
            unset($credentials['refreshToken']);
        }

        return $credentials;
    }

    private function assertProvider(string $provider): void
    {
        if (! array_key_exists($provider, self::SCOPES)) {
            throw new StorageAuthorizationException('OAuth is not supported for this storage provider.');
        }
    }

    private function requireClientId(array $configuration): void
    {
        if (! is_string($configuration['clientId'] ?? null) || $configuration['clientId'] === '') {
            throw new StorageAuthorizationException('OAuth client ID is missing; update the storage connection.');
        }
    }

    private function validateCodeVerifier(?string $codeVerifier): void
    {
        if ($codeVerifier !== null
            && (strlen($codeVerifier) < 43 || strlen($codeVerifier) > 128
                || preg_match('/^[A-Za-z0-9._~-]+$/', $codeVerifier) !== 1)) {
            throw new StorageAuthorizationException('The OAuth PKCE verifier is invalid.');
        }
    }
}
