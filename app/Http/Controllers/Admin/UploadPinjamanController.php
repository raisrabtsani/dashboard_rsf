<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\PinjamanCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Controller TIPIS — parsing berkas ada di PinjamanCsvImportService.
 */
class UploadPinjamanController extends Controller
{
    public function __construct(private readonly PinjamanCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/Pinjaman', [
            'kolomBerkas' => PinjamanCsvImportService::KOLOM,
        ]);
    }

    public function riwayat(): JsonResponse
    {
        return response()->json(['riwayat' => $this->service->riwayat()]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:204800'],
        ], [
            'berkas.max' => 'Ukuran berkas maksimal 200 MB.',
        ]);

        $berkas = $request->file('berkas');

        try {
            $hasil = $this->service->validasi($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            return $this->gagalTakTerduga($e, 'validasi');
        }

        return response()->json([
            'message' => sprintf(
                'Validasi selesai: %s baris valid dan %s baris tidak valid.',
                number_format($hasil['laporan']['valid'], 0, ',', '.'),
                number_format($hasil['laporan']['tidak_valid'], 0, ',', '.'),
            ),
            'hasil' => $hasil,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            // Maksimal 200 MB. Nilai Laravel menggunakan satuan kilobyte.
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:204800'],
        ], [
            'berkas.max' => 'Ukuran berkas maksimal 200 MB.',
        ]);

        $berkas = $request->file('berkas');

        try {
            $hasil = $this->service->impor($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            return $this->gagalTakTerduga($e, 'upload');
        }

        $pesan = sprintf(
            '%s baris masuk untuk tanggal %s (segmen: %s).',
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['tanggal']),
            implode(', ', $hasil['segmen']),
        );

        if ($hasil['dilewati'] > 0) {
            $pesan .= sprintf(
                ' %s baris dilewati karena kombinasi tanggal + segmennya sudah ada.',
                number_format($hasil['dilewati'], 0, ',', '.'),
            );
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    private function gagalTakTerduga(Throwable $e, string $tahap): JsonResponse
    {
        report($e);

        $detail = trim($e->getMessage());
        if ($detail === '') {
            $detail = class_basename($e);
        }

        return response()->json([
            'message' => "Gagal {$tahap} data Pinjaman: {$detail}",
            'jenis_error' => class_basename($e),
        ], 500);
    }

    public function unduh(string $tanggal): StreamedResponse
    {
        $baris = $this->service->barisUntukUnduh($tanggal);

        abort_if($baris === [], 404, "Tidak ada data pinjaman untuk tanggal {$tanggal}.");

        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');
            fputcsv($keluaran, PinjamanCsvImportService::KOLOM, escape: '');

            foreach ($baris as $b) {
                fputcsv($keluaran, array_values($b), escape: '');
            }

            fclose($keluaran);
        }, "pinjaman-{$tanggal}.csv", ['Content-Type' => 'text/csv']);
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
