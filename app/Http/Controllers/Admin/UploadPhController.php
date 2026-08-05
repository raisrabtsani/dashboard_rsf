<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\PhCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadPhController extends Controller
{
    public function __construct(private readonly PhCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/Ph', [
            'kolomBerkas' => PhCsvImportService::KOLOM,
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
        ]);

        $berkas = $request->file('berkas');

        try {
            // timpa=false: periode yang sudah ada DILEWATI, bukan membatalkan
            // seluruh berkas. Untuk menimpa, pakai `php artisan import:ph --timpa`.
            $hasil = $this->service->impor($berkas->getRealPath(), $berkas->getClientOriginalName());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $pesan = sprintf(
            '%s baris masuk untuk periode %s.',
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['periode']),
        );

        if ($hasil['sumber'] > $hasil['baris']) {
            $pesan .= sprintf(
                ' %s baris berkas dijumlahkan jadi %s baris.',
                number_format($hasil['sumber'], 0, ',', '.'),
                number_format($hasil['baris'], 0, ',', '.'),
            );
        }

        if ($hasil['dilewati'] !== []) {
            $pesan .= ' Periode yang sudah ada dilewati: '.implode(', ', $hasil['dilewati']).'.';
        }

        if ($hasil['fallback'] > 0) {
            $pesan .= sprintf(
                ' %s baris tanpa uker valid dicatat di level cabang.',
                number_format($hasil['fallback'], 0, ',', '.'),
            );
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    public function unduh(string $periode): StreamedResponse
    {
        $baris = $this->service->barisUntukUnduh($periode);

        abort_if($baris === [], 404, "Tidak ada data PH untuk periode {$periode}.");

        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');
            fputcsv($keluaran, PhCsvImportService::KOLOM, escape: '');

            foreach ($baris as $b) {
                fputcsv($keluaran, array_values($b), escape: '');
            }

            fclose($keluaran);
        }, "ph-{$periode}.csv", ['Content-Type' => 'text/csv']);
    }

    public function hapusPeriode(string $periode): JsonResponse
    {
        $jumlah = $this->service->hapusPeriode($periode);

        return response()->json(['message' => "{$jumlah} baris periode {$periode} dihapus."]);
    }

    public function hapusTahun(int $tahun): JsonResponse
    {
        $jumlah = $this->service->hapusTahun($tahun);

        return response()->json(['message' => "{$jumlah} baris PH tahun {$tahun} dihapus."]);
    }
}
