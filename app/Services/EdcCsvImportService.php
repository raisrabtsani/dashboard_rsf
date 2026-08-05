<?php

namespace App\Services;

use App\Models\Edc;

/** Importer data aktual EDC. Logika di MerchantAktualImportService. */
class EdcCsvImportService extends MerchantAktualImportService
{
    protected function modelClass(): string
    {
        return Edc::class;
    }

    protected function serviceClass(): string
    {
        return EdcService::class;
    }
}
