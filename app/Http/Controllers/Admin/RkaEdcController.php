<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\RkaEdcCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RkaEdcController extends Controller
{
    public function __construct(private readonly RkaEdcCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Rka/Edc', [
            'kolomBerkas' => RkaEdcCsvImportService::KOLOM,
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json(['ringkasan' => $this->service->ringkasan()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:20480'],
        ]);

        $berkas = $request->file('berkas');

        try {
            $hasil = $this->service->impor($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $pesan = sprintf(
            '%s baris RKA tahun %s tersimpan.',
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['tahun']),
        );

        if (($hasil['sales_volume_baris_digabung'] ?? 0) > 0) {
            $pesan .= sprintf(
                ' RKA Sales Volume: %s baris sumber dijumlahkan menjadi %s kombinasi berdasarkan id_uker, bulan, tahun, dan KPI yang sama.',
                number_format($hasil['sales_volume_baris_sumber'], 0, ',', '.'),
                number_format($hasil['sales_volume_kombinasi'], 0, ',', '.'),
            );
        }

        if ($hasil['dilewati'] > 0) {
            $pesan .= sprintf(
                ' %s baris dilewati karena kolom target kosong.',
                number_format($hasil['dilewati'], 0, ',', '.'),
            );
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    public function hapusTahun(int $tahun): JsonResponse
    {
        $jumlah = $this->service->hapusTahun($tahun);

        return response()->json(['message' => "{$jumlah} baris RKA tahun {$tahun} dihapus."]);
    }
}
