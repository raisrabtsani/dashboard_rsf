<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\RkaEdc;
use App\Models\RkaLaba;
use App\Models\RkaPinjaman;
use App\Models\RkaPinjamanCommercial;
use App\Models\RkaQris;
use App\Models\RkaRecovery;
use App\Models\RkaSimpanan;
use App\Models\RkaSimpananWholesale;
use App\Models\Uker;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    /**
     * Domain RKA yang boleh dikelola dari komponen admin bersama.
     *
     * @var array<string, array{model: class-string, kelompok: string}>
     */
    private const RKA_DOMAIN = [
        'simpanan' => ['model' => RkaSimpanan::class, 'kelompok' => 'produk'],
        'pinjaman' => ['model' => RkaPinjaman::class, 'kelompok' => 'kualitas'],
        'recovery' => ['model' => RkaRecovery::class, 'kelompok' => 'segmen'],
        'laba' => ['model' => RkaLaba::class, 'kelompok' => 'segmen'],
        'edc' => ['model' => RkaEdc::class, 'kelompok' => 'kpi'],
        'qris' => ['model' => RkaQris::class, 'kelompok' => 'kpi'],
        'simpanan-wholesale' => ['model' => RkaSimpananWholesale::class, 'kelompok' => 'produk'],
        'pinjaman-commercial' => ['model' => RkaPinjamanCommercial::class, 'kelompok' => 'kualitas'],
    ];

    /**
     * Tabel yang menyimpan referensi kantor operasional.
     *
     * Penghapusan master ditolak selama id masih dipakai agar data historis
     * dashboard tidak menjadi yatim atau gagal karena foreign key.
     *
     * @var list<string>
     */
    private const TABEL_REFERENSI_KANTOR = [
        'users',
        'simpanan',
        'rka_simpanan',
        'pinjaman',
        'rka_pinjaman',
        'ph',
        'recovery',
        'rka_recovery',
        'laba',
        'rka_laba',
        'simpanan_hourly',
        'edc',
        'qris',
        'rka_edc',
        'rka_qris',
        'simpanan_wholesale',
        'rka_simpanan_wholesale',
        'pinjaman_commercial',
        'rka_pinjaman_commercial',
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Index');
    }

    /**
     * Master kantor cabang dan unit kerja.
     */
    public function kantorCabang(): Response
    {
        $cabang = Cabang::query()
            ->tanpaRegionOffice()
            ->with([
                'area:id,nama',
                'uker' => fn ($query) => $query
                    ->tanpaRegionOffice()
                    ->orderBy('tipe')
                    ->orderBy('nama')
                    ->select(['id', 'cabang_id', 'nama', 'tipe', 'updated_at']),
            ])
            ->orderBy('nama')
            ->get(['id', 'area_id', 'nama', 'updated_at'])
            ->map(fn (Cabang $item) => [
                'id' => (int) $item->id,
                'nama' => $item->nama,
                'area_id' => $item->area_id === null ? null : (int) $item->area_id,
                'area' => $item->area?->nama ?? 'Tanpa Area',
                'updated_at' => $item->updated_at?->toDateTimeString(),
                'jumlah_uker' => $item->uker->count(),
                'uker' => $item->uker
                    ->map(fn (Uker $uker) => [
                        'id' => (int) $uker->id,
                        'cabang_id' => (int) $uker->cabang_id,
                        'nama' => $uker->nama,
                        'tipe' => $uker->tipe ?? Uker::TIPE_UNIT,
                        'updated_at' => $uker->updated_at?->toDateTimeString(),
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('Admin/Cabang/Index', [
            'areas' => Area::query()->orderBy('nama')->get(['id', 'nama']),
            'cabang' => $cabang,
            'tipeUker' => [Uker::TIPE_BO, Uker::TIPE_SBO, Uker::TIPE_UNIT, Uker::TIPE_KK],
            'statistik' => [
                'total_area' => Area::query()->count(),
                'total_cabang' => $cabang->count(),
                'total_uker' => $cabang->sum('jumlah_uker'),
            ],
        ]);
    }

    /**
     * Unggah master kantor dari CSV/Excel dengan format code_uker.
     *
     * Kolom wajib: id_cabang, id_uker, Nama Cabang, Nama Uker.
     * Kolom opsional: id_area, tipe. Data disimpan dengan upsert sehingga file
     * yang sama dapat diunggah ulang untuk memperbarui nama, induk, dan tipe.
     */
    public function uploadKantor(Request $request): JsonResponse
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:'.implode(',', Spreadsheet::EKSTENSI), 'max:20480'],
        ], [], ['berkas' => 'berkas']);

        $berkas = $request->file('berkas');

        try {
            $sumber = Spreadsheet::baca(
                $berkas->getRealPath(),
                ['id_cabang', 'id_uker', 'Nama Cabang', 'Nama Uker'],
                $berkas->getClientOriginalName(),
            );

            if ($sumber->isEmpty()) {
                throw ImportException::berkas('Berkas tidak memiliki baris data.');
            }

            $hasil = $this->simpanMasterKantor($sumber->all());
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => sprintf(
                'Master kantor berhasil diperbarui: %s cabang baru, %s cabang diperbarui, %s unit baru, dan %s unit diperbarui.',
                number_format($hasil['cabang_baru'], 0, ',', '.'),
                number_format($hasil['cabang_update'], 0, ',', '.'),
                number_format($hasil['uker_baru'], 0, ',', '.'),
                number_format($hasil['uker_update'], 0, ',', '.'),
            ),
            'hasil' => $hasil,
        ]);
    }

    /**
     * Template upload master kantor yang bisa langsung dibuka di Excel.
     */
    public function templateKantor(): StreamedResponse
    {
        $header = ['id_cabang', 'id_uker', 'Nama Cabang', 'Nama Uker', 'id_area', 'tipe'];
        $contoh = [
            [116, 116, 'KC Karawang', 'KC Karawang', 1, 'BO'],
            [116, 597, 'KC Karawang', 'KCP Rengas Dengklok', 1, 'UNIT'],
        ];

        return response()->streamDownload(function () use ($header, $contoh) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $header, escape: '');

            foreach ($contoh as $baris) {
                fputcsv($output, $baris, escape: '');
            }

            fclose($output);
        }, 'template-master-kantor.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function updateCabang(Request $request, Cabang $cabang): JsonResponse
    {
        $this->pastikanBukanRegionOffice((int) $cabang->id, 'cabang');

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
        ]);

        $cabang->update([
            'nama' => trim($data['nama']),
            'area_id' => $data['area_id'] ?? null,
        ]);

        return response()->json(['message' => "Kantor cabang {$cabang->nama} berhasil diperbarui."]);
    }

    public function updateUker(Request $request, Uker $uker): JsonResponse
    {
        $this->pastikanBukanRegionOffice((int) $uker->id, 'unit kerja');

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'cabang_id' => ['required', 'integer', 'exists:cabang,id'],
            'tipe' => ['required', 'string', 'in:BO,SBO,UNIT,KK'],
        ]);

        $uker->update([
            'nama' => trim($data['nama']),
            'cabang_id' => (int) $data['cabang_id'],
            'tipe' => $data['tipe'],
        ]);

        return response()->json(['message' => "Unit kerja {$uker->nama} berhasil diperbarui."]);
    }

    public function destroyCabang(Cabang $cabang): JsonResponse
    {
        $this->pastikanBukanRegionOffice((int) $cabang->id, 'cabang');

        $jumlahUker = Uker::query()->where('cabang_id', $cabang->id)->count();

        if ($jumlahUker > 0) {
            return response()->json([
                'message' => "Cabang {$cabang->nama} masih memiliki {$jumlahUker} unit kerja. Pindahkan atau hapus unit kerja tersebut terlebih dahulu.",
            ], 409);
        }

        $dipakai = $this->jumlahReferensi('cabang_id', (int) $cabang->id);

        if ($dipakai > 0) {
            return response()->json([
                'message' => "Cabang {$cabang->nama} masih dipakai oleh {$dipakai} baris data/user dan tidak dapat dihapus.",
            ], 409);
        }

        $nama = $cabang->nama;
        $cabang->delete();

        return response()->json(['message' => "Kantor cabang {$nama} berhasil dihapus."]);
    }

    public function destroyUker(Uker $uker): JsonResponse
    {
        $this->pastikanBukanRegionOffice((int) $uker->id, 'unit kerja');

        $dipakai = $this->jumlahReferensi('uker_id', (int) $uker->id);

        if ($dipakai > 0) {
            return response()->json([
                'message' => "Unit kerja {$uker->nama} masih dipakai oleh {$dipakai} baris data/user dan tidak dapat dihapus. Gunakan Edit bila unit hanya berganti nama atau pindah cabang.",
            ], 409);
        }

        $nama = $uker->nama;
        $uker->delete();

        return response()->json(['message' => "Unit kerja {$nama} berhasil dihapus."]);
    }

    /**
     * Hapus beberapa kantor cabang dan/atau unit kerja sekaligus.
     *
     * Unit kerja selalu diproses lebih dahulu agar sebuah cabang yang ikut
     * dipilih dapat dihapus setelah seluruh unit bebas referensi terhapus.
     * Data yang masih dipakai user, data aktual, atau RKA dilewati dan
     * dilaporkan tanpa membatalkan penghapusan item lain yang aman.
     */
    public function destroyKantorPilihan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pilihan' => ['required', 'array', 'min:1', 'max:1000'],
            'pilihan.*.jenis' => ['required', 'string', 'in:cabang,uker'],
            'pilihan.*.id' => ['required', 'integer', 'min:1'],
        ]);

        $pilihan = collect($data['pilihan'])
            ->map(fn (array $item) => [
                'jenis' => $item['jenis'],
                'id' => (int) $item['id'],
            ])
            ->unique(fn (array $item) => $item['jenis'].':'.$item['id'])
            ->sortBy(fn (array $item) => $item['jenis'] === 'uker' ? 0 : 1)
            ->values();

        $terhapus = [];
        $gagal = [];

        foreach ($pilihan as $item) {
            if ($item['id'] === Region::OFFICE_ID) {
                $gagal[] = [
                    ...$item,
                    'nama' => 'Region Office',
                    'alasan' => 'Master Region Office tidak boleh dihapus.',
                ];

                continue;
            }

            if ($item['jenis'] === 'uker') {
                /** @var Uker|null $uker */
                $uker = Uker::query()->find($item['id']);

                if ($uker === null) {
                    $gagal[] = [...$item, 'nama' => 'Unit kerja', 'alasan' => 'Data tidak ditemukan.'];
                    continue;
                }

                $dipakai = $this->jumlahReferensi('uker_id', (int) $uker->id);

                if ($dipakai > 0) {
                    $gagal[] = [
                        ...$item,
                        'nama' => $uker->nama,
                        'alasan' => "Masih dipakai oleh {$dipakai} baris data/user.",
                    ];
                    continue;
                }

                $nama = $uker->nama;

                try {
                    $uker->delete();
                    $terhapus[] = [...$item, 'nama' => $nama];
                } catch (\Throwable) {
                    $gagal[] = [
                        ...$item,
                        'nama' => $nama,
                        'alasan' => 'Masih memiliki relasi data yang mencegah penghapusan.',
                    ];
                }

                continue;
            }

            /** @var Cabang|null $cabang */
            $cabang = Cabang::query()->find($item['id']);

            if ($cabang === null) {
                $gagal[] = [...$item, 'nama' => 'Kantor cabang', 'alasan' => 'Data tidak ditemukan.'];
                continue;
            }

            $jumlahUker = Uker::query()->where('cabang_id', $cabang->id)->count();

            if ($jumlahUker > 0) {
                $gagal[] = [
                    ...$item,
                    'nama' => $cabang->nama,
                    'alasan' => "Masih memiliki {$jumlahUker} unit kerja.",
                ];
                continue;
            }

            $dipakai = $this->jumlahReferensi('cabang_id', (int) $cabang->id);

            if ($dipakai > 0) {
                $gagal[] = [
                    ...$item,
                    'nama' => $cabang->nama,
                    'alasan' => "Masih dipakai oleh {$dipakai} baris data/user.",
                ];
                continue;
            }

            $nama = $cabang->nama;

            try {
                $cabang->delete();
                $terhapus[] = [...$item, 'nama' => $nama];
            } catch (\Throwable) {
                $gagal[] = [
                    ...$item,
                    'nama' => $nama,
                    'alasan' => 'Masih memiliki relasi data yang mencegah penghapusan.',
                ];
            }
        }

        $jumlahTerhapus = count($terhapus);
        $jumlahGagal = count($gagal);
        $message = number_format($jumlahTerhapus, 0, ',', '.').' data berhasil dihapus.';

        if ($jumlahGagal > 0) {
            $message .= ' '.number_format($jumlahGagal, 0, ',', '.').' data dilewati karena masih digunakan atau belum memenuhi syarat penghapusan.';
        }

        return response()->json([
            'message' => $message,
            'terhapus' => $terhapus,
            'gagal' => $gagal,
        ]);
    }

    /**
     * Riwayat RKA per periode dan kelompok. Data diseragamkan agar seluruh
     * halaman RKA memakai satu komponen Vue tanpa mengubah struktur tabel asli.
     */
    public function rkaData(string $domain): JsonResponse
    {
        $konfigurasi = self::RKA_DOMAIN[$domain] ?? null;
        abort_if($konfigurasi === null, 404);

        $model = $konfigurasi['model'];
        $kelompok = $konfigurasi['kelompok'];

        $ringkasan = $model::query()
            ->groupBy('tahun', 'bulan', $kelompok)
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->orderBy($kelompok)
            ->selectRaw(
                "tahun, bulan, {$kelompok} as kelompok, COUNT(*) as jumlah_baris, "
                .'SUM(target) as total_target, MAX(updated_at) as diubah'
            )
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                'bulan' => (int) $r->bulan,
                'kelompok' => (string) $r->kelompok,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_target' => Satuan::toJuta($r->total_target),
                'diubah' => $r->diubah === null
                    ? null
                    : Carbon::parse($r->diubah)->toDateTimeString(),
            ])
            ->all();

        return response()->json(['ringkasan' => $ringkasan]);
    }

    /**
     * Hapus beberapa baris ringkasan RKA sekaligus. Setiap pilihan menunjuk
     * tepat ke kombinasi tahun + bulan + kelompok, bukan menghapus satu tahun
     * secara tidak sengaja.
     */
    public function hapusRkaPilihan(Request $request, string $domain): JsonResponse
    {
        $konfigurasi = self::RKA_DOMAIN[$domain] ?? null;
        abort_if($konfigurasi === null, 404);

        $data = $request->validate([
            'pilihan' => ['required', 'array', 'min:1', 'max:500'],
            'pilihan.*.tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'pilihan.*.bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'pilihan.*.kelompok' => ['required', 'string', 'max:191'],
        ]);

        $model = $konfigurasi['model'];
        $kelompok = $konfigurasi['kelompok'];

        $jumlah = DB::transaction(function () use ($data, $model, $kelompok) {
            $terhapus = 0;

            foreach ($data['pilihan'] as $pilihan) {
                $terhapus += $model::query()
                    ->where('tahun', $pilihan['tahun'])
                    ->where('bulan', $pilihan['bulan'])
                    ->where($kelompok, $pilihan['kelompok'])
                    ->delete();
            }

            return $terhapus;
        });

        return response()->json([
            'message' => number_format($jumlah, 0, ',', '.').' baris RKA terpilih berhasil dihapus.',
        ]);
    }

    /**
     * @param  list<array<string, string>>  $sumber
     * @return array{cabang_baru:int,cabang_update:int,uker_baru:int,uker_update:int}
     */
    private function simpanMasterKantor(array $sumber): array
    {
        $cabangMap = [];
        $ukerMap = [];
        $areaValid = Area::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $areaValid = array_flip($areaValid);

        foreach ($sumber as $index => $baris) {
            $nomor = $index + 2;
            $idCabang = $this->idPositif($baris['id_cabang'] ?? '', 'id_cabang', $nomor);
            $idUker = $this->idPositif($baris['id_uker'] ?? '', 'id_uker', $nomor);
            $namaCabang = trim((string) ($baris['Nama Cabang'] ?? ''));
            $namaUker = trim((string) ($baris['Nama Uker'] ?? ''));

            if ($namaCabang === '' || $namaUker === '') {
                throw ImportException::berkas("Baris {$nomor}: Nama Cabang dan Nama Uker wajib diisi.");
            }

            $idArea = null;
            $punyaKolomArea = array_key_exists('id_area', $baris) && trim((string) $baris['id_area']) !== '';

            if ($punyaKolomArea) {
                $idArea = $this->idPositif($baris['id_area'], 'id_area', $nomor);

                if (! isset($areaValid[$idArea])) {
                    throw ImportException::berkas("Baris {$nomor}: id_area {$idArea} tidak ditemukan di master area.");
                }
            }

            $tipe = strtoupper(trim((string) ($baris['tipe'] ?? '')));
            $tipe = $tipe !== '' ? $tipe : Uker::tipeDari($idUker, $idCabang, $namaUker);
            $tipe ??= Uker::TIPE_UNIT;

            if (! in_array($tipe, [Uker::TIPE_BO, Uker::TIPE_SBO, Uker::TIPE_UNIT, Uker::TIPE_KK, Uker::TIPE_REGION], true)) {
                throw ImportException::berkas("Baris {$nomor}: tipe {$tipe} tidak valid. Gunakan BO, SBO, UNIT, atau KK.");
            }

            if (isset($cabangMap[$idCabang]) && $cabangMap[$idCabang]['nama'] !== $namaCabang) {
                throw ImportException::berkas("Baris {$nomor}: id_cabang {$idCabang} memiliki Nama Cabang yang berbeda dalam satu berkas.");
            }

            if (isset($ukerMap[$idUker]) && $ukerMap[$idUker]['cabang_id'] !== $idCabang) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$idUker} terhubung ke lebih dari satu cabang dalam satu berkas.");
            }

            $cabangSebelumnya = $cabangMap[$idCabang] ?? null;

            $cabangMap[$idCabang] = [
                'id' => $idCabang,
                'nama' => $namaCabang,
                'area_id_baru' => $punyaKolomArea
                    ? $idArea
                    : ($cabangSebelumnya['area_id_baru'] ?? null),
                'punya_area' => $punyaKolomArea || ($cabangSebelumnya['punya_area'] ?? false),
            ];

            $ukerMap[$idUker] = [
                'id' => $idUker,
                'cabang_id' => $idCabang,
                'nama' => $namaUker,
                'tipe' => $tipe,
            ];
        }

        $idCabang = array_keys($cabangMap);
        $idUker = array_keys($ukerMap);
        $cabangLama = Cabang::query()->whereIn('id', $idCabang)->get()->keyBy('id');
        $ukerLama = Uker::query()->whereIn('id', $idUker)->get()->keyBy('id');
        $waktu = now();

        $barisCabang = collect($cabangMap)->map(function (array $item) use ($cabangLama, $waktu) {
            /** @var Cabang|null $lama */
            $lama = $cabangLama->get($item['id']);

            return [
                'id' => $item['id'],
                'region_id' => $lama?->region_id ?? Region::OFFICE_ID,
                'area_id' => $item['punya_area'] ? $item['area_id_baru'] : $lama?->area_id,
                'nama' => $item['nama'],
                'created_at' => $lama?->created_at ?? $waktu,
                'updated_at' => $waktu,
            ];
        })->values()->all();

        $barisUker = collect($ukerMap)->map(function (array $item) use ($ukerLama, $waktu) {
            /** @var Uker|null $lama */
            $lama = $ukerLama->get($item['id']);

            return [
                ...$item,
                'created_at' => $lama?->created_at ?? $waktu,
                'updated_at' => $waktu,
            ];
        })->values()->all();

        DB::transaction(function () use ($barisCabang, $barisUker) {
            Cabang::query()->upsert(
                $barisCabang,
                ['id'],
                ['region_id', 'area_id', 'nama', 'updated_at'],
            );

            Uker::query()->upsert(
                $barisUker,
                ['id'],
                ['cabang_id', 'nama', 'tipe', 'updated_at'],
            );
        });

        return [
            'cabang_baru' => count($cabangMap) - $cabangLama->count(),
            'cabang_update' => $cabangLama->count(),
            'uker_baru' => count($ukerMap) - $ukerLama->count(),
            'uker_update' => $ukerLama->count(),
        ];
    }

    private function idPositif(mixed $nilai, string $kolom, int $nomor): int
    {
        $teks = trim((string) $nilai);

        if ($teks === '' || preg_match('/^\d+$/', $teks) !== 1 || (int) $teks <= 0) {
            throw ImportException::berkas("Baris {$nomor}: {$kolom} harus berupa angka bulat positif.");
        }

        return (int) ltrim($teks, '0');
    }

    private function pastikanBukanRegionOffice(int $id, string $jenis): void
    {
        if ($id === Region::OFFICE_ID) {
            throw ValidationException::withMessages([
                'id' => "Master {$jenis} Region Office tidak boleh diubah atau dihapus.",
            ]);
        }
    }

    private function jumlahReferensi(string $kolom, int $id): int
    {
        $jumlah = 0;

        foreach (self::TABEL_REFERENSI_KANTOR as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, $kolom)) {
                continue;
            }

            $jumlah += DB::table($tabel)->where($kolom, $id)->count();
        }

        return $jumlah;
    }
}
