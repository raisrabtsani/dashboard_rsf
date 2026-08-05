<?php

namespace App\Http\Controllers;

use App\Services\QrisService;

/** Endpoint api/merchant/qris/*. Logika di MerchantApiController + QrisService. */
class QrisController extends MerchantApiController
{
    public function __construct(QrisService $service)
    {
        parent::__construct($service);
    }
}
