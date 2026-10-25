<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\OAuth;

use Microsoft\Graph\Graph;
use SchoolPalm\StorageApi\Settings\OAuthTokenState;

final class RefreshingMicrosoftGraph extends Graph
{
    public function __construct(private readonly OAuthTokenState $state)
    {
        parent::__construct();
    }

    public function createRequest($requestType, $endpoint)
    {
        $this->setAccessToken($this->state->accessToken());

        return parent::createRequest($requestType, $endpoint);
    }
}
