<?php

namespace App\Services;

use App\Models\RkaQris;

/** Importer target RKA QRIS. Logika di MerchantRkaImportService. */
class RkaQrisCsvImportService extends MerchantRkaImportService
{
    protected function modelClass(): string
    {
        return RkaQris::class;
    }

    protected function serviceClass(): string
    {
        return QrisService::class;
    }
}
