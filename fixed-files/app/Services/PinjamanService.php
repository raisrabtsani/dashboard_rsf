<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Pinjaman;
use App\Models\Region;
use App\Models\RkaPinjaman;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seluruh query & kalkulasi dashboard Pinjaman (Kredit).
 *
 * Mengikuti pola SimpananService (lihat CLAUDE.md §4), dengan tiga perbedaan:
 *
 *  1. Nilainya `baki_debet`, berdimensi KUALITAS (Lancar/SML/NPL).
 *     Tab yang aktif menentukan angka mana yang ditampilkan:
 *     Total = Lancar+SML+NPL (OS), SML = SML saja, NPL = NPL saja.
 *  2. Tab SML & NPL mengganti kolom YoY dengan MoM ("Date to Date").
 *  3. Rollup Region 855 IKUT dihitung (segmen Menengah dikelola level Region),
 *     berbeda dari Simpanan yang mengecualikannya.
 */
class PinjamanService
{
    use MenyaringOrganisasi;

    public const TAB_TOTAL = 'total';

    public const TAB_SML = 'sml';

    public const TAB_NPL = 'npl';

    /** @var list<string> */
    public const TAB = [self::TAB_TOTAL, self::TAB_SML, self::TAB_NPL];

    /**
     * Tab yang arah pencapaiannya TERBALIK: makin kecil makin baik.
     *
     * @var list<string>
     */
    public const TAB_INVERSE = [self::TAB_SML, self::TAB_NPL];

    /**
     * Rollup Region Office DIIKUTKAN di angka Pinjaman.
     *
     * Segmen Menengah dikelola di level Region dengan cabang_id 855, jadi
     * mengecualikannya akan membuat total OS kurang sebesar porsi Menengah.
     * Konsekuensi yang disengaja: jumlah baris tabel Kinerja Cabang lebih kecil
     * dari kartu Total, karena 855 tetap disembunyikan dari daftar cabang.
     */
    public const ROLLUP_REGION_ID = Region::OFFICE_ID;

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        $produk = Pinjaman::query()
            ->whereNotNull('segmentasi')
            ->where('segmentasi', '!=', '')
            ->distinct()
            ->pluck('segmentasi')
            ->merge(
                RkaPinjaman::query()
                    ->whereNotNull('segmentasi')
                    ->where('segmentasi', '!=', '')
                    ->distinct()
                    ->pluck('segmentasi'),
            )
            ->map(fn ($nilai) => trim((string) $nilai))
            ->filter()
            ->unique(fn ($nilai) => strtolower((string) $nilai))
            ->sortBy(fn ($nilai) => strtolower((string) $nilai))
            ->values()
            ->all();

        $segmentasi = Pinjaman::query()
            ->whereNotNull('segmen')
            ->where('segmen', '!=', '')
            ->distinct()
            ->pluck('segmen')
            ->merge(
                RkaPinjaman::query()
                    ->whereNotNull('segmen')
                    ->where('segmen', '!=', '')
                    ->distinct()
                    ->pluck('segmen'),
            )
            ->map(fn ($nilai) => trim((string) $nilai))
            ->filter()
            ->unique(fn ($nilai) => strtolower((string) $nilai))
            ->sortBy(fn ($nilai) => array_search($nilai, Pinjaman::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($nilai, Pinjaman::SEGMEN, true))
            ->values()
            ->all();

        $periode = Pinjaman::query()
            ->whereNotNull('tanggal')
            ->distinct()
            ->orderByDesc('tanggal')
            ->pluck('tanggal')
            ->map(fn ($tanggal) => Carbon::parse($tanggal)->toDateString())
            ->values()
            ->all();

        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'produk' => $produk,
            'segmentasi' => $segmentasi,
            'periode' => $periode,
            'tanggal_maks' => $this->tanggalTerakhir(),
            'tanggal_min' => Pinjaman::query()->min('tanggal'),
        ];
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = Pinjaman::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $tanggal, dalam
     * lingkup filter. Dipakai halaman Ringkasan; memakai TAB_TOTAL supaya
     * mencakup semua kualitas (OS penuh). Lihat SimpananService.
     */
    public function tanggalTersediaHingga(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        return $this->tanggalTersedia(Carbon::parse($tanggal), self::TAB_TOTAL, $areaId, $cabangId, $ukerId);
    }

    /**
     * Kartu KPI per segmen + Total, sesuai tab yang aktif.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $referensi = $this->tanggalReferensi($posisi, $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);

        $dibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();
        $nilai = $this->nilaiPerTanggalSegmen($dibaca->all(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);
        $target = $this->targetPerSegmen($posisi->year, $posisi->month, $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);

        $segmen = $this->segmenTersedia($nilai, $target);
        $posisiNilai = $nilai[$posisi->toDateString()] ?? [];

        $kartu = [];

        foreach ([...$segmen, 'total'] as $key) {
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
                'judul' => $key === 'total' ? $this->judulTotal($tab) : $key,
                'nilai' => Satuan::toJuta($aktual),
                'delta' => $delta,
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($aktual - $rka),
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'tab' => $tab,
            'inverse' => in_array($tab, self::TAB_INVERSE, true),
            // Label kolom delta terakhir berubah per tab; frontend memakainya apa adanya.
            'label_delta' => $this->labelDelta($tab),
            'tanggal_referensi' => $referensi,
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren harian nilai tab aktif, dipecah per bulan (pola sama dengan Simpanan).
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        // Samakan pola dashboard DPK: Desember tahun sebelumnya + 5 bulan
        // terakhir sampai bulan posisi. Frontend akan merangkai 6 seri tetap
        // dari rentang ini.
        $rentang = [
            $posisi->copy()->subYear()->month(12)->startOfMonth()->toDateString(),
            $posisi->toDateString(),
        ];

        $harian = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(baki_debet) as total')
            ->pluck('total', 'tanggal');

        return [
            'tahun' => $posisi->year,
            'tab' => $tab,
            'seri' => $this->perBulan($harian),
        ];
    }

    /**
     * ENDPOINT KHUSUS PINJAMAN: tren harian dipecah PER SEGMEN (bukan per bulan).
     *
     * @return array<string, mixed>
     */
    public function chartSegmen(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        // Samakan pola trend per segmen dengan DPK: Desember tahun sebelumnya
        // + 5 bulan terakhir sampai bulan posisi.
        $rentang = [
            $posisi->copy()->subYear()->month(12)->startOfMonth()->toDateString(),
            $posisi->toDateString(),
        ];

        $baris = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal', 'segmen')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get();

        $seri = $baris
            ->groupBy('segmen')
            ->map(function (Collection $rows, string $segmen) {
                $perTanggal = $rows->mapWithKeys(
                    fn ($r) => [Carbon::parse($r->tanggal)->toDateString() => (float) $r->total],
                );

                return [
                    'segmen' => $segmen,
                    'seri' => $this->perBulan(collect($perTanggal)),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'tab' => $tab,
            'seri' => $seri,
        ];
    }

    /**
     * ENDPOINT KHUSUS PINJAMAN: rincian per PRODUK (kolom `segmentasi`),
     * dikelompokkan per segmen, untuk tab aktif.
     *
     * Tiap kelompok = satu baris total segmen + satu baris tiap produk, masing-
     * masing lengkap dengan target/pencapaian/gap/delta dalam satuan JUTA —
     * bentuknya sama seperti kartu snapshot supaya frontend memakainya seragam.
     * Frontend mengambil tab total/sml/npl sekaligus lalu menurunkan rasio
     * %SML/%NPL di sisi klien (pola sama dengan tabel Kinerja Cabang).
     *
     * @return array<string, mixed>
     */
    public function produk(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $referensi = $this->tanggalReferensi($posisi, $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);
        $dibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();

        $nilai = $this->nilaiPerTanggalProduk($dibaca->all(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);
        $target = $this->targetPerProduk($posisi->year, $posisi->month, $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);

        $posisiNilai = $nilai[$posisi->toDateString()] ?? [];
        $peta = $this->petaSegmenProduk($posisiNilai, $target);

        $kelompok = [];

        foreach ($peta as $segmen => $daftarProduk) {
            $totalPembanding = [];
            foreach ($referensi as $jenis => $tgl) {
                $totalPembanding[$jenis] = $tgl === null ? null : array_sum($nilai[$tgl][$segmen] ?? []);
            }

            $produk = [];
            foreach ($daftarProduk as $segmentasi) {
                $aktual = (float) ($posisiNilai[$segmen][$segmentasi] ?? 0);
                $rka = (float) ($target[$segmen][$segmentasi] ?? 0);

                $pembanding = [];
                foreach ($referensi as $jenis => $tgl) {
                    $pembanding[$jenis] = $tgl === null ? null : (float) ($nilai[$tgl][$segmen][$segmentasi] ?? 0);
                }

                $produk[] = ['segmentasi' => $segmentasi] + $this->barisPencapaian($aktual, $rka, $pembanding);
            }

            // Produk terurut nilai desc; produk tanpa nama ("") ke akhir.
            usort($produk, fn ($a, $b) => ($b['nilai'] ?? 0) <=> ($a['nilai'] ?? 0));

            $kelompok[] = [
                'segmen' => $segmen,
                'total' => $this->barisPencapaian(
                    array_sum($posisiNilai[$segmen] ?? []),
                    array_sum($target[$segmen] ?? []),
                    $totalPembanding,
                ),
                'produk' => $produk,
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'tab' => $tab,
            'inverse' => in_array($tab, self::TAB_INVERSE, true),
            'label_delta' => $this->labelDelta($tab),
            'kelompok' => $kelompok,
        ];
    }

    /**
     * Tabel kinerja per cabang; dengan cabang_id, grouping pindah ke per-uker.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $aktual = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            // 855 disembunyikan dari BARIS tabel walau ikut di kartu Total.
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::ROLLUP_REGION_ID))
            ->where('tanggal', $posisi->toDateString())
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(baki_debet) as total")
            ->pluck('total', 'entitas_id');

        $target = $this->targetDasar(
            $tab,
            $posisi->year,
            $posisi->month,
            $perUker ? null : $areaId,
            $cabangId,
            $ukerId,
            $produk,
            $segmentasi,
        )
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::ROLLUP_REGION_ID))
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id');

        $entitas = $perUker
            ? Uker::query()
                ->with(['cabang:id,nama,area_id', 'cabang.area:id,nama'])
                ->whereIn('id', $aktual->keys())
                ->get(['id', 'cabang_id', 'nama'])
                ->keyBy('id')
            : Cabang::query()
                ->with('area:id,nama')
                ->whereIn('id', $aktual->keys())
                ->get(['id', 'area_id', 'nama'])
                ->keyBy('id');

        $baris = $aktual->map(function ($total, $entitasId) use ($target, $entitas, $perUker) {
            $rka = (float) ($target[$entitasId] ?? 0);
            $nilai = (float) $total;
            $detail = $entitas->get((int) $entitasId);
            $cabang = $perUker ? $detail?->cabang : $detail;

            return [
                'id' => (int) $entitasId,
                'nama' => $detail?->nama ?? (string) $entitasId,
                'cabang' => $perUker ? $cabang?->nama : null,
                'area_head_id' => $cabang?->area_id,
                'area_head' => $cabang?->area?->nama,
                'nilai' => Satuan::toJuta($nilai),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($nilai - $rka),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'tab' => $tab,
            'inverse' => in_array($tab, self::TAB_INVERSE, true),
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * Tanggal pembanding tiap jenis delta.
     *
     * Tab Total memakai D-1/MTD/YTD/YoY. Tab SML & NPL mengganti YoY dengan MoM
     * ("Date to Date"): tanggal posisi dibanding tanggal yang SAMA bulan lalu.
     *
     * @return array<string, string|null>
     */
    private function tanggalReferensi(Carbon $posisi, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi),
        ];

        $referensi[$this->kunciDeltaTerakhir($tab)] = in_array($tab, self::TAB_INVERSE, true)
            ? $this->tanggalMom($posisi, $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            : $this->tanggalTersedia($posisi->copy()->subYear(), $tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi);

        return $referensi;
    }

    /**
     * MoM "Date to Date": tanggal yang sama bulan lalu (18 Jun -> 18 Mei).
     *
     * subMonthNoOverflow supaya 31 Mar tidak melompat ke 2/3 Mar. Kalau tanggal
     * itu tidak ada datanya (akhir pekan/libur), fallback ke tanggal TERAKHIR
     * yang tersedia di bulan tersebut — bukan ke tanggal mana pun sebelumnya,
     * supaya perbandingannya tetap antar-bulan yang benar.
     */
    private function tanggalMom(Carbon $posisi, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): ?string
    {
        $target = $posisi->copy()->subMonthNoOverflow();

        $persis = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->where('tanggal', $target->toDateString())
            ->exists();

        if ($persis) {
            return $target->toDateString();
        }

        $fallback = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereBetween('tanggal', [
                $target->copy()->startOfMonth()->toDateString(),
                $target->copy()->endOfMonth()->toDateString(),
            ])
            ->max('tanggal');

        return $fallback === null ? null : Carbon::parse($fallback)->toDateString();
    }

    private function tanggalTersedia(Carbon $batas, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): ?string
    {
        $tanggal = $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Query dasar TERBATAS pada kualitas yang relevan dengan tab.
     */
    private function dasar(string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): Builder
    {
        return $this->dasarSemua($areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereIn('kualitas', $this->kualitasTab($tab));
    }

    /**
     * Query dasar tanpa penyaringan kualitas (dipakai tabel rincian per produk).
     */
    private function dasarSemua(?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): Builder
    {
        // Tidak ada pengecualian 855: segmen Menengah dikelola level Region.
        return $this->filterDimensi(
            $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId),
            $produk,
            $segmentasi,
        );
    }

    /**
     * Filter UI: `produk` mengarah ke kolom `segmentasi`, sedangkan
     * `segmentasi` mengarah ke kolom `segmen` pada tabel aktual dan RKA.
     */
    private function filterDimensi(Builder $query, ?string $produk, ?string $segmentasi): Builder
    {
        $produk = $produk === null ? null : trim($produk);
        $segmentasi = $segmentasi === null ? null : trim($segmentasi);

        return $query
            ->when($produk !== null && $produk !== '', fn (Builder $q) => $q->where('segmentasi', $produk))
            ->when($segmentasi !== null && $segmentasi !== '', fn (Builder $q) => $q->where('segmen', $segmentasi));
    }

    /**
     * @return list<string>
     */
    private function kualitasTab(string $tab): array
    {
        return match ($tab) {
            self::TAB_SML => [Pinjaman::KUALITAS_SML],
            self::TAB_NPL => [Pinjaman::KUALITAS_NPL],
            default => Pinjaman::KUALITAS,
        };
    }

    /**
     * Query target RKA untuk tab aktif.
     *
     * Format RKA terbaru menyimpan target Total langsung sebagai kualitas `OS`,
     * sedangkan format lama menyimpan Lancar + SML + NPL. Untuk kompatibilitas,
     * baris format lama hanya dipakai pada kombinasi yang belum mempunyai OS.
     * Dengan begitu OS tidak dijumlahkan lagi dengan SML/NPL (double count),
     * tetapi data RKA lama tetap terbaca.
     */
    private function targetDasar(
        string $tab,
        int $tahun,
        int $bulan,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
        ?string $segmentasi = null,
    ): Builder
    {
        $query = $this->filterDimensi(
            $this->filterOrganisasi(RkaPinjaman::query(), $areaId, $cabangId, $ukerId),
            $produk,
            $segmentasi,
        )
            ->where('tahun', $tahun)
            ->where('bulan', $bulan);

        if ($tab !== self::TAB_TOTAL) {
            return $query->whereIn('kualitas', $this->kualitasTab($tab));
        }

        return $query->where(function (Builder $target) {
            $target
                ->where('kualitas', RkaPinjaman::KUALITAS_OS)
                ->orWhere(function (Builder $legacy) {
                    $legacy
                        ->whereIn('kualitas', Pinjaman::KUALITAS)
                        ->whereNotExists(function ($os) {
                            $os->selectRaw('1')
                                ->from('rka_pinjaman as rka_os')
                                ->whereColumn('rka_os.uker_id', 'rka_pinjaman.uker_id')
                                ->whereColumn('rka_os.segmen', 'rka_pinjaman.segmen')
                                ->whereColumn('rka_os.segmentasi', 'rka_pinjaman.segmentasi')
                                ->whereColumn('rka_os.tahun', 'rka_pinjaman.tahun')
                                ->whereColumn('rka_os.bulan', 'rka_pinjaman.bulan')
                                ->where('rka_os.kualitas', RkaPinjaman::KUALITAS_OS);
                        });
                });
        });
    }

    private function tab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, self::TAB, true) ? $tab : self::TAB_TOTAL;
    }

    private function judulTotal(string $tab): string
    {
        return match ($tab) {
            self::TAB_SML => 'Total SML',
            self::TAB_NPL => 'Total NPL',
            default => 'Total OS',
        };
    }

    private function kunciDeltaTerakhir(string $tab): string
    {
        return in_array($tab, self::TAB_INVERSE, true) ? 'mom' : 'yoy';
    }

    /**
     * Label kolom delta untuk KpiCard; urutannya = urutan tampil.
     *
     * @return list<array{key: string, label: string}>
     */
    private function labelDelta(string $tab): array
    {
        return [
            ['key' => 'dtd', 'label' => 'D-1'],
            ['key' => 'mtd', 'label' => 'MTD'],
            ['key' => 'ytd', 'label' => 'YTD'],
            in_array($tab, self::TAB_INVERSE, true)
                ? ['key' => 'mom', 'label' => 'Date to Date']
                : ['key' => 'yoy', 'label' => 'YoY'],
        ];
    }

    /**
     * @param  list<string>  $tanggal
     * @return array<string, array<string, float>>
     */
    private function nilaiPerTanggalSegmen(array $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'segmen')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows->pluck('total', 'segmen')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function targetPerSegmen(int $tahun, int $bulan, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        return $this->targetDasar($tab, $tahun, $bulan, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->groupBy('segmen')
            ->selectRaw('segmen, SUM(target) as total')
            ->pluck('total', 'segmen')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Rakit satu baris pencapaian (nilai/target/penc/gap/delta) dalam satuan JUTA.
     * Dipakai baris total segmen maupun baris produk di endpoint {@see produk()}.
     *
     * @param  array<string, float|null>  $pembanding  nilai pembanding per jenis delta; null = tanggal referensi tak tersedia
     * @return array<string, mixed>
     */
    private function barisPencapaian(float $aktual, float $rka, array $pembanding): array
    {
        $delta = [];

        foreach ($pembanding as $jenis => $nilai) {
            $delta[$jenis] = Delta::hitung($aktual, $nilai);
        }

        return [
            'nilai' => Satuan::toJuta($aktual),
            'target' => Satuan::toJuta($rka),
            'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
            'gap' => Satuan::toJuta($aktual - $rka),
            'delta' => $delta,
        ];
    }

    /**
     * Nilai tab aktif per (segmen, segmentasi) untuk beberapa tanggal sekaligus.
     *
     * @param  list<string>  $tanggal
     * @return array<string, array<string, array<string, float>>> [tanggal => [segmen => [segmentasi => nilai]]]
     */
    private function nilaiPerTanggalProduk(array $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($tab, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'segmen', 'segmentasi')
            ->selectRaw('tanggal, segmen, segmentasi, SUM(baki_debet) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows
                ->groupBy('segmen')
                ->map(fn (Collection $g) => $g->pluck('total', 'segmentasi')->map(fn ($v) => (float) $v)->all())
                ->all())
            ->all();
    }

    /**
     * Target RKA tab aktif per (segmen, segmentasi).
     *
     * @return array<string, array<string, float>> [segmen => [segmentasi => target]]
     */
    private function targetPerProduk(int $tahun, int $bulan, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $produk = null, ?string $segmentasi = null): array
    {
        return $this->targetDasar($tab, $tahun, $bulan, $areaId, $cabangId, $ukerId, $produk, $segmentasi)
            ->groupBy('segmen', 'segmentasi')
            ->selectRaw('segmen, segmentasi, SUM(target) as total')
            ->get()
            ->groupBy('segmen')
            ->map(fn (Collection $g) => $g->pluck('total', 'segmentasi')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * Peta segmen => daftar produk, gabungan dari nilai posisi & target. Segmen
     * urut mengikuti Pinjaman::SEGMEN; segmen/produk bertarget tanpa realisasi
     * tetap ikut supaya pencapaiannya tampil (bukan hilang).
     *
     * @param  array<string, array<string, float>>  $posisiNilai
     * @param  array<string, array<string, float>>  $target
     * @return array<string, list<string>>
     */
    private function petaSegmenProduk(array $posisiNilai, array $target): array
    {
        $segmen = collect(array_keys($posisiNilai))
            ->merge(array_keys($target))
            ->unique()
            ->sortBy(fn (string $s) => array_search($s, Pinjaman::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($s, Pinjaman::SEGMEN, true))
            ->values();

        $peta = [];

        foreach ($segmen as $s) {
            $peta[$s] = collect(array_keys($posisiNilai[$s] ?? []))
                ->merge(array_keys($target[$s] ?? []))
                ->unique()
                ->values()
                ->all();
        }

        return $peta;
    }

    /**
     * Segmen yang PUNYA data aktual saja. Segmen yang hanya muncul di target
     * (RKA) sengaja TIDAK ditampilkan: penamaan RKA yang berbeda dari aktual
     * (mis. "Mikro" vs "Micro") kalau tidak disaring akan menghasilkan kartu
     * hantu ber-nilai 0 yang menggandakan segmen aslinya.
     *
     * @param  array<string, array<string, float>>  $nilai
     * @return list<string>
     */
    private function segmenTersedia(array $nilai): array
    {
        return collect($nilai)
            ->flatMap(fn (array $per) => array_keys($per))
            ->unique()
            ->sortBy(fn (string $s) => array_search($s, Pinjaman::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($s, Pinjaman::SEGMEN, true))
            ->values()
            ->all();
    }

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Kelompokkan deret harian jadi satu seri per bulan.
     *
     * Bulan diturunkan DI PHP — query hanya group by tanggal (portable).
     *
     * @param  Collection<string, mixed>  $harian
     * @return list<array<string, mixed>>
     */
    private function perBulan(Collection $harian): array
    {
        return collect($harian)
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
            // Gunakan key tahun-bulan agar Desember tahun lalu tidak tergabung
            // dengan Desember tahun berjalan.
            ->groupBy(fn ($total, string $tgl) => Carbon::parse($tgl)->format('Y-m'), preserveKeys: true)
            ->map(function (Collection $bulanan, string $periode) {
                $tanggalPeriode = Carbon::createFromFormat('Y-m', $periode)->startOfMonth();
                $bulan = (int) $tanggalPeriode->month;

                return [
                    'periode' => $periode,
                    'tahun' => (int) $tanggalPeriode->year,
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
    }
}
