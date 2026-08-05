<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\RkaPinjamanCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RkaPinjamanController extends Controller
{
    public function __construct(private readonly RkaPinjamanCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Rka/Pinjaman', [
            'kolomBerkas' => RkaPinjamanCsvImportService::KOLOM,
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
