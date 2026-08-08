<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Recovery;
use App\Models\Region;
use App\Models\RkaRecovery;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seluruh query & kalkulasi dashboard Recovery (penagihan kredit hapus buku).
 *
 * Mengikuti pola SimpananService (lihat CLAUDE.md §4), dengan satu perbedaan
 * inti: dimensinya SEGMEN (Micro/SME/Consumer), bukan produk, dan segmen mentah
 * dari DB DINORMALKAN KE KANONIK SAAT BACA di sini — bukan saat import. Sumber
 * data berubah antar tahun (mis. "SME" dipecah jadi "Small" + "Medium"), jadi
 * pelipatan lewat Recovery::SEGMEN_RAW membuat perbandingan YoY apple-to-apple
 * tanpa mengutak-atik data historis.
 *
 * Kontrak service (berlaku semua domain): tidak menyentuh Request/auth(), tidak
 * mengembalikan response HTTP, semua nilai uang keluar dalam JUTA, query portable
 * MySQL/SQLite (bulan diturunkan di PHP).
 */
class RecoveryService
{
    use MenyaringOrganisasi;

    /**
     * Rollup Region Office dikecualikan — sama seperti Simpanan. Recovery adalah
     * kegiatan operasional level cabang/uker; 855 bukan kantor operasional.
     */
    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

    /**
     * Opsi filter: area, cabang (opsional per area), uker (opsional per cabang),
     * plus rentang tanggal yang datanya tersedia.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'tanggal_maks' => $this->tanggalTerakhir(),
            'tanggal_min' => Recovery::query()->min('tanggal'),
        ];
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = Recovery::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $tanggal, dalam
     * lingkup filter. Dipakai halaman Ringkasan (lihat SimpananService).
     */
    public function tanggalTersediaHingga(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        return $this->tanggalTersedia(Carbon::parse($tanggal), $areaId, $cabangId, $ukerId);
    }

    /**
     * Kartu KPI: Total + satu kartu per segmen kanonik, lengkap dengan delta &
     * pencapaian vs RKA bulan posisi.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tanggalDiminta = Carbon::parse($tanggal)->startOfDay();
        $tanggalData = $this->tanggalTersedia($tanggalDiminta, $areaId, $cabangId, $ukerId);
        $posisi = Carbon::parse($tanggalData ?? $tanggalDiminta->toDateString())->startOfDay();

        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId),
            'yoy' => $this->tanggalTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId),
        ];

        $dibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();
        $nilai = $this->nilaiPerTanggalSegmen($dibaca->all(), $areaId, $cabangId, $ukerId);
        $target = $this->targetPerSegmen($posisi->year, $posisi->month, $areaId, $cabangId, $ukerId);

        $segmen = $this->segmenTersedia($nilai, $target);
        $posisiNilai = $nilai[$posisi->toDateString()] ?? [];

        $kartu = [];

        foreach (['total', ...$segmen] as $key) {
            $aktual = $key === 'total' ? array_sum($posisiNilai) : (float) ($posisiNilai[$key] ?? 0);
            $rka = $key === 'total' ? array_sum($target) : (float) ($target[$key] ?? 0);

            $delta = [];

            foreach ($referensi as $jenis => $tanggalRef) {
                $baris = $tanggalRef === null ? null : ($nilai[$tanggalRef] ?? []);
                $pembanding = $baris === null
                    ? null
                    : ($key === 'total' ? array_sum($baris) : (float) ($baris[$key] ?? 0));

                $delta[$jenis] = Delta::hitung($aktual, $pembanding);
            }

            $kartu[] = [
                'key' => $key,
                'judul' => $key === 'total' ? 'Total Recovery EC' : strtoupper($key),
                'nilai' => Satuan::toJuta($aktual),
                'delta' => $delta,
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($aktual - $rka),
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'tanggal_diminta' => $tanggalDiminta->toDateString(),
            'tanggal_disesuaikan' => $tanggalData !== null && $tanggalData !== $tanggalDiminta->toDateString(),
            'tanggal_referensi' => $referensi,
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren recovery harian untuk total / satu segmen.
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $scope = null): array
    {
        $tanggalDiminta = Carbon::parse($tanggal)->startOfDay();
        $tanggalData = $this->tanggalTersedia($tanggalDiminta, $areaId, $cabangId, $ukerId);
        $posisi = Carbon::parse($tanggalData ?? $tanggalDiminta->toDateString())->startOfDay();
        $segmenDipilih = $this->scopeToSegmen($scope);

        // Enam seri seperti dashboard referensi:
        // Desember tahun sebelumnya + lima bulan berjalan sampai bulan posisi.
        $periode = collect(range(0, 4))
            ->map(fn (int $mundur) => $posisi->copy()->subMonthsNoOverflow($mundur)->format('Y-m'))
            ->push($posisi->copy()->subYear()->month(12)->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        $awal = Carbon::createFromFormat('Y-m-d', ((string) $periode->first()).'-01')->startOfDay();

        $rows = $this->dasar($areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$awal->toDateString(), $posisi->toDateString()])
            ->groupBy('tanggal', 'segmen')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, segmen, SUM(actual) as total')
            ->get();

        $harian = $rows
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(function (Collection $perTanggal) use ($segmenDipilih) {
                $dilipat = $this->lipatSegmen($perTanggal);

                return $segmenDipilih === null
                    ? array_sum($dilipat)
                    : (float) ($dilipat[$segmenDipilih] ?? 0.0);
            })
            ->filter(fn ($total, string $tgl) => $periode->contains(Carbon::parse($tgl)->format('Y-m')));

        $seri = $harian
            ->groupBy(fn ($total, string $tgl) => Carbon::parse($tgl)->format('Y-m'), preserveKeys: true)
            ->map(function (Collection $bulanan, string $periodeKey) {
                $periodeTanggal = Carbon::createFromFormat('Y-m', $periodeKey)->startOfMonth();
                $bulan = (int) $periodeTanggal->month;

                return [
                    'periode' => $periodeKey,
                    'tahun' => (int) $periodeTanggal->year,
                    'bulan' => $bulan,
                    'nama' => self::NAMA_BULAN[$bulan],
                    'titik' => $bulanan->map(fn ($total, string $tgl) => [
                        'tanggal' => $tgl,
                        'hari' => (int) Carbon::parse($tgl)->day,
                        'nilai' => Satuan::toJuta($total),
                    ])->values()->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'tahun' => $posisi->year,
            'tanggal' => $posisi->toDateString(),
            'tanggal_diminta' => $tanggalDiminta->toDateString(),
            'scope' => $segmenDipilih ?? 'total',
            'seri' => $seri,
        ];
    }

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Tabel kinerja per cabang / uker dengan delta D-1, MTD, YTD, dan YoY.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tanggalDiminta = Carbon::parse($tanggal)->startOfDay();
        $tanggalData = $this->tanggalTersedia($tanggalDiminta, $areaId, $cabangId, $ukerId);
        $posisi = Carbon::parse($tanggalData ?? $tanggalDiminta->toDateString())->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId),
            'yoy' => $this->tanggalTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId),
        ];

        $aktual = $this->nilaiPerEntitasTanggal($posisi->toDateString(), $kolom, $areaId, $cabangId, $ukerId);
        $pembanding = collect($referensi)->map(
            fn (?string $tgl) => $this->nilaiPerEntitasTanggal($tgl, $kolom, $areaId, $cabangId, $ukerId),
        );

        $target = $this->filterOrganisasi(
            RkaRecovery::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $perUker ? null : $areaId,
            $cabangId,
            $ukerId,
        )
            ->where('tahun', $posisi->year)
            ->where('bulan', $posisi->month)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id');

        $entitas = $perUker
            ? Uker::query()->with('cabang.area')->whereIn('id', $aktual->keys())->get()->keyBy('id')
            : Cabang::query()->with('area')->whereIn('id', $aktual->keys())->get()->keyBy('id');

        $baris = $aktual->map(function ($total, $entitasId) use ($target, $entitas, $pembanding, $perUker) {
            $nilai = (float) $total;
            $rka = (float) ($target[$entitasId] ?? 0.0);
            $kantor = $entitas->get($entitasId);
            $cabang = $perUker ? $kantor?->cabang : $kantor;
            $area = $cabang?->area;

            return [
                'id' => (int) $entitasId,
                'nama' => $kantor?->nama ?? (string) $entitasId,
                'cabang_nama' => $perUker ? $cabang?->nama : null,
                'area_nama' => $area?->nama,
                'nilai' => Satuan::toJuta($nilai),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($nilai - $rka),
                'dtd' => Delta::hitung($nilai, $pembanding->get('dtd')?->get($entitasId)),
                'mtd' => Delta::hitung($nilai, $pembanding->get('mtd')?->get($entitasId)),
                'ytd' => Delta::hitung($nilai, $pembanding->get('ytd')?->get($entitasId)),
                'yoy' => Delta::hitung($nilai, $pembanding->get('yoy')?->get($entitasId)),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'tanggal_diminta' => $tanggalDiminta->toDateString(),
            'tanggal_disesuaikan' => $tanggalData !== null && $tanggalData !== $tanggalDiminta->toDateString(),
            'tanggal_referensi' => $referensi,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    /**
     * Query dasar dengan filter organisasi + pengecualian rollup 855.
     */
    private function dasar(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            Recovery::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $batas.
     */
    private function tanggalTersedia(Carbon $batas, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $tanggal = $this->dasar($areaId, $cabangId, $ukerId)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * @param  list<string>  $tanggal
     * @return array<string, array<string, float>>
     */
    private function nilaiPerTanggalSegmen(array $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($areaId, $cabangId, $ukerId)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'segmen')
            ->selectRaw('tanggal, segmen, SUM(actual) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $this->lipatSegmen($rows))
            ->all();
    }

    /**
     * @return Collection<int|string, float>
     */
    private function nilaiPerEntitasTanggal(?string $tanggal, string $kolom, ?int $areaId, ?int $cabangId, ?int $ukerId): Collection
    {
        if ($tanggal === null) {
            return collect();
        }

        return $this->dasar($areaId, $cabangId, $ukerId)
            ->where('tanggal', $tanggal)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(actual) as total")
            ->pluck('total', 'entitas_id');
    }

    /**
     * @return array<string, float>
     */
    private function targetPerSegmen(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $rows = $this->filterOrganisasi(
            RkaRecovery::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        )
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('segmen')
            ->selectRaw('segmen, SUM(target) as total')
            ->get();

        return $this->lipatSegmen($rows);
    }

    /**
     * Lipat baris {segmen (mentah), total} menjadi [segmen kanonik => jumlah].
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, float>
     */
    private function lipatSegmen(Collection $rows): array
    {
        $per = [];

        foreach ($rows as $row) {
            $kanonik = Recovery::kanonik((string) $row->segmen) ?? (string) $row->segmen;
            $per[$kanonik] = ($per[$kanonik] ?? 0.0) + (float) $row->total;
        }

        return $per;
    }

    /**
     * @param  array<string, array<string, float>>  $nilai
     * @param  array<string, float>  $target
     * @return list<string>
     */
    private function segmenTersedia(array $nilai, array $target): array
    {
        $dariNilai = collect($nilai)->flatMap(fn (array $per) => array_keys($per));

        return $dariNilai
            ->merge(array_keys($target))
            ->unique()
            ->sortBy(fn (string $s) => array_search($s, Recovery::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($s, Recovery::SEGMEN, true))
            ->values()
            ->all();
    }

    private function scopeToSegmen(?string $scope): ?string
    {
        $scope = strtolower(trim((string) $scope));

        return match ($scope) {
            'micro' => 'Micro',
            'sme' => 'SME',
            'consumer' => 'Consumer',
            default => null,
        };
    }
}
