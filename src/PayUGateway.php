<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin;

final class PayUGateway
{
    public const FACTORY_NAME = 'refberg_payu';

    /** Key under which the plugin keeps its data in Payment::$details. */
    public const DETAILS_KEY = 'refberg_payu';

    private function __construct()
    {
    }
}
