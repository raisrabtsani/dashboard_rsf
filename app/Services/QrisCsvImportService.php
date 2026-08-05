<?php

namespace App\Services;

use App\Models\Qris;

/** Importer data aktual QRIS. Logika di MerchantAktualImportService. */
class QrisCsvImportService extends MerchantAktualImportService
{
    protected function modelClass(): string
    {
        return Qris::class;
    }

    protected function serviceClass(): string
    {
        return QrisService::class;
    }
}
