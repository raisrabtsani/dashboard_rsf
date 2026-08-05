<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\SimpananCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller TIPIS — tidak ada parsing berkas di sini.
 * Seluruh pembacaan & validasi ada di SimpananCsvImportService.
 */
class UploadSimpananController extends Controller
{
    public function __construct(private readonly SimpananCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/Simpanan', [
            'kolomBerkas' => SimpananCsvImportService::KOLOM,
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
            // 422 = isi berkas salah, 409 = bentrok dengan data yang sudah ada.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => sprintf(
                '%d baris masuk untuk tanggal %s.',
                $hasil['baris'],
                implode(', ', $hasil['tanggal']),
            ),
            'hasil' => $hasil,
        ]);
    }

    public function unduh(string $tanggal): StreamedResponse
    {
        $baris = $this->service->barisUntukUnduh($tanggal);

        abort_if($baris === [], 404, "Tidak ada data simpanan untuk tanggal {$tanggal}.");

        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');
            fputcsv($keluaran, SimpananCsvImportService::KOLOM, escape: '');

            foreach ($baris as $b) {
                fputcsv($keluaran, array_values($b), escape: '');
            }

            fclose($keluaran);
        }, "simpanan-{$tanggal}.csv", ['Content-Type' => 'text/csv']);
    }

    public function hapusTanggal(string $tanggal): JsonResponse
    {
        $jumlah = $this->service->hapusTanggal($tanggal);

        return response()->json(['message' => "{$jumlah} baris tanggal {$tanggal} dihapus."]);
    }

    public function hapusBulan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $jumlah = $this->service->hapusBulan($data['tahun'], $data['bulan']);

        return response()->json([
            'message' => "{$jumlah} baris pada {$data['bulan']}/{$data['tahun']} dihapus.",
        ]);
    }
}
