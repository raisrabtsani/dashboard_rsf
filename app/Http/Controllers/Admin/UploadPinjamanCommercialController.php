<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\PinjamanCommercialCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Controller TIPIS — parsing di PinjamanCommercialCsvImportService.
 */
class UploadPinjamanCommercialController extends Controller
{
    public function __construct(private readonly PinjamanCommercialCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/PinjamanCommercial', [
            'kolomBerkas' => PinjamanCommercialCsvImportService::KOLOM,
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
        $this->siapkanProsesBesar();

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
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:204800'],
        ], [
            'berkas.max' => 'Ukuran berkas maksimal 200 MB.',
        ], ['berkas' => 'berkas']);

        $berkas = $request->file('berkas');
        $this->siapkanProsesBesar();

        try {
            $hasil = $this->service->impor($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            return $this->gagalTakTerduga($e, 'upload');
        }

        $pesan = sprintf(
            '%s baris agregat tersimpan dari %s baris valid sumber untuk tanggal %s.',
            number_format($hasil['baris'], 0, ',', '.'),
            number_format($hasil['sumber'], 0, ',', '.'),
            implode(', ', $hasil['tanggal']),
        );

        $tidakValid = (int) ($hasil['laporan']['tidak_valid'] ?? 0);

        if ($tidakValid > 0) {
            $pesan .= sprintf(
                ' %s baris tidak valid dilewati dan tidak diimpor.',
                number_format($tidakValid, 0, ',', '.'),
            );
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    /**
     * File sumber dapat berisi ribuan baris. Beri waktu proses yang cukup tanpa
     * mengubah konfigurasi global PHP.
     */
    private function siapkanProsesBesar(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }
    }

    private function gagalTakTerduga(Throwable $e, string $tahap): JsonResponse
    {
        report($e);

        $detail = trim($e->getMessage());
        if ($detail === '') {
            $detail = class_basename($e);
        }

        return response()->json([
            'message' => "Gagal {$tahap} data Pinjaman Commercial: {$detail}",
            'jenis_error' => class_basename($e),
        ], 500);
    }

    public function unduh(string $tanggal): StreamedResponse
    {
        $baris = $this->service->barisUntukUnduh($tanggal);

        abort_if($baris === [], 404, "Tidak ada data Pinjaman Commercial untuk tanggal {$tanggal}.");

        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');
            fputcsv($keluaran, PinjamanCommercialCsvImportService::KOLOM, escape: '');

            foreach ($baris as $b) {
                fputcsv($keluaran, array_values($b), escape: '');
            }

            fclose($keluaran);
        }, "pinjaman-commercial-{$tanggal}.csv", ['Content-Type' => 'text/csv']);
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
