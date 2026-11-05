<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin;

final class TpayGateway
{
    public const FACTORY_NAME = 'refberg_tpay';

    /** Key under which the plugin keeps its data in Payment::$details. */
    public const DETAILS_KEY = 'refberg_tpay';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const CONFIG_MERCHANT_ID = 'merchant_id';

    public const CONFIG_CLIENT_ID = 'client_id';

    public const CONFIG_CLIENT_SECRET = 'client_secret';

    public const CONFIG_SECURITY_CODE = 'security_code';

    private function __construct()
    {
    }
}
