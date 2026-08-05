<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\LabaCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller TIPIS — upload data aktual Laba (bulanan).
 * Seluruh pembacaan & validasi ada di LabaCsvImportService.
 */
class UploadLabaController extends Controller
{
    public function __construct(private readonly LabaCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/Laba', [
            'kolomBerkas' => LabaCsvImportService::KOLOM,
        ]);
    }

    public function riwayat(): JsonResponse
    {
        return response()->json(['riwayat' => $this->service->riwayat()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:20480'],
        ], [], ['berkas' => 'berkas']);

        $berkas = $request->file('berkas');

        try {
            $hasil = $this->service->impor($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $pesan = sprintf(
            '%s baris laba tahun %s tersimpan.',
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['tahun']),
        );

        if ($hasil['dilewati'] > 0) {
            $pesan .= sprintf(
                ' %s baris dilewati karena kolom laba kosong.',
                number_format($hasil['dilewati'], 0, ',', '.'),
            );
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    public function unduh(int $tahun, int $bulan): StreamedResponse
    {
        $baris = $this->service->barisUntukUnduh($tahun, $bulan);

        abort_if($baris === [], 404, "Tidak ada data laba untuk {$bulan}/{$tahun}.");

        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');
            fputcsv($keluaran, LabaCsvImportService::KOLOM, escape: '');

            foreach ($baris as $b) {
                fputcsv($keluaran, array_values($b), escape: '');
            }

            fclose($keluaran);
        }, sprintf('laba-%04d-%02d.csv', $tahun, $bulan), ['Content-Type' => 'text/csv']);
    }

    public function hapusPeriode(int $tahun, int $bulan): JsonResponse
    {
        $jumlah = $this->service->hapusPeriode($tahun, $bulan);

        return response()->json(['message' => "{$jumlah} baris pada {$bulan}/{$tahun} dihapus."]);
    }

    public function hapusTahun(int $tahun): JsonResponse
    {
        $jumlah = $this->service->hapusTahun($tahun);

        return response()->json(['message' => "{$jumlah} baris laba tahun {$tahun} dihapus."]);
    }
}
