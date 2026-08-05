<?php

namespace App\Http\Controllers;

use App\Services\EdcService;

/** Endpoint api/merchant/edc/*. Logika di MerchantApiController + EdcService. */
class EdcController extends MerchantApiController
{
    public function __construct(EdcService $service)
    {
        parent::__construct($service);
    }
}
