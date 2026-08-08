<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\RkaRecoveryCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RkaRecoveryController extends Controller
{
    public function __construct(private readonly RkaRecoveryCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Rka/Recovery', [
            'kolomBerkas' => RkaRecoveryCsvImportService::KOLOM,
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
            '%s baris sumber dihitung dengan SUMIF id_uker + bulan + tahun menjadi %s baris RKA tahun %s. Total target Rp %s.',
            number_format($hasil['sumber'], 0, ',', '.'),
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['tahun']),
            number_format($hasil['total_target'], 2, ',', '.'),
        );

        if ($hasil['sumif']['baris_tergabung'] > 0) {
            $pesan .= sprintf(
                ' %s baris sumber tergabung dalam %s kombinasi id_uker, bulan, dan tahun.',
                number_format($hasil['sumif']['baris_tergabung'], 0, ',', '.'),
                number_format($hasil['sumif']['kombinasi'], 0, ',', '.'),
            );
        }

        if ($hasil['dilewati'] > 0) {
            $pesan .= sprintf(
                ' %s baris dilewati karena kolom target kosong (dianggap tidak punya target).',
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
