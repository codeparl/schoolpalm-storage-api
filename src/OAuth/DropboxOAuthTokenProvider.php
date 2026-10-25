<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\OAuth;

use GuzzleHttp\Exception\ClientException;
use SchoolPalm\StorageApi\Settings\OAuthTokenState;
use Spatie\Dropbox\RefreshableTokenProvider;

final class DropboxOAuthTokenProvider implements RefreshableTokenProvider
{
    public function __construct(private readonly OAuthTokenState $state) {}

    public function getToken(): string
    {
        return $this->state->accessToken();
    }

    public function refresh(ClientException $exception): bool
    {
        return $this->state->forceRefresh();
    }
}
