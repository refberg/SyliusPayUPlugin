<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin;

final class PayUGateway
{
    public const FACTORY_NAME = 'refberg_payu';

    /** Key under which the plugin keeps its data in Payment::$details. */
    public const DETAILS_KEY = 'refberg_payu';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const CONFIG_POS_ID = 'pos_id';

    public const CONFIG_SECOND_KEY = 'second_key';

    public const CONFIG_OAUTH_CLIENT_ID = 'oauth_client_id';

    public const CONFIG_OAUTH_CLIENT_SECRET = 'oauth_client_secret';

    private function __construct()
    {
    }
}
