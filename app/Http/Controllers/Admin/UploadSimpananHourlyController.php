<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Services\SimpananHourlyCsvImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UploadSimpananHourlyController extends Controller
{
    public function __construct(private readonly SimpananHourlyCsvImportService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Upload/SimpananHourly', [
            'kolomBerkas' => SimpananHourlyCsvImportService::KOLOM,
        ]);
    }

    public function riwayat(): JsonResponse
    {
        return response()->json(['riwayat' => $this->service->riwayat()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:20480'],
            // Jam TIDAK ada di berkas — admin memilihnya di form.
            'jam' => ['required', 'integer', 'min:0', 'max:23'],
        ]);

        $berkas = $request->file('berkas');

        try {
            $hasil = $this->service->impor(
                $berkas->getRealPath(),
                (int) $data['jam'],
                $berkas->getClientOriginalName(),
            );
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $pesan = sprintf(
            '%s baris masuk untuk %s pukul %02d:00.',
            number_format($hasil['baris'], 0, ',', '.'),
            implode(', ', $hasil['tanggal']),
            $hasil['jam'],
        );

        if ($hasil['sumber'] > $hasil['baris']) {
            $pesan .= sprintf(
                ' %s baris berkas dijumlahkan jadi %s baris posisi.',
                number_format($hasil['sumber'], 0, ',', '.'),
                number_format($hasil['baris'], 0, ',', '.'),
            );
        }

        if ($hasil['bukan_eom'] !== []) {
            $pesan .= ' Peringatan: '.implode(', ', $hasil['bukan_eom']).
                ' bukan tanggal akhir bulan — DPK Hourly dimaksudkan untuk hari EOM.';
        }

        return response()->json(['message' => $pesan, 'hasil' => $hasil]);
    }

    public function hapusJam(Request $request, string $tanggal): JsonResponse
    {
        $data = $request->validate(['jam' => ['required', 'integer', 'min:0', 'max:23']]);

        $jumlah = $this->service->hapusJam($tanggal, (int) $data['jam']);

        return response()->json([
            'message' => sprintf('%d baris %s pukul %02d:00 dihapus.', $jumlah, $tanggal, $data['jam']),
        ]);
    }

    public function hapusTanggal(string $tanggal): JsonResponse
    {
        $jumlah = $this->service->hapusTanggal($tanggal);

        return response()->json(['message' => "{$jumlah} baris tanggal {$tanggal} (semua jam) dihapus."]);
    }
}
