<?php

namespace App\Services;

use App\Models\RkaEdc;

/** Importer target RKA EDC. Logika di MerchantRkaImportService. */
class RkaEdcCsvImportService extends MerchantRkaImportService
{
    protected function modelClass(): string
    {
        return RkaEdc::class;
    }

    protected function serviceClass(): string
    {
        return EdcService::class;
    }
}
