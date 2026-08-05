<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Basis importer data AKTUAL merchant (EDC / QRIS) — harian, per KPI.
 *
 * Format (long): id_cabang | id_uker | kpi | tanggal | actual
 *
 * Yang membedakannya dari importer lain: NAMA KPI dari berkas dinormalkan ke
 * KODE KANONIK katalog service (kanonikKpi) — nama di berkas sering beda
 * spasi/kapitalisasi. Data di-UPSERT pada (uker, kpi, tanggal) supaya idempoten;
 * baris berkunci kembar di dalam satu berkas ditolak.
 */
abstract class MerchantAktualImportService
{
    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'kpi' => ['indikator', 'metrik', 'nama kpi', 'parameter'],
        'tanggal' => ['tgl', 'date', 'periode'],
        'actual' => ['aktual', 'nilai', 'value', 'jumlah', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'kpi', 'tanggal', 'actual'];

    /** Model aktual (Edc::class / Qris::class). */
    abstract protected function modelClass(): string;

    /** Service pemilik katalog KPI (EdcService::class / QrisService::class). */
    abstract protected function serviceClass(): string;

    /**
     * @return array{tanggal: list<string>, baris: int, kpi: list<string>, total: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $baris = $this->baca($path, $namaAsli ?? basename($path));

        $model = $this->modelClass();

        DB::transaction(function () use ($baris, $model) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => $model::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'kpi', 'tanggal'],
                ['cabang_id', 'actual', 'updated_at'],
            ));
        });

        return [
            'tanggal' => $baris->pluck('tanggal')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'kpi' => $baris->pluck('kpi')->unique()->sort()->values()->all(),
            'total' => (float) $baris->sum(fn (array $b) => $b['actual']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 60): array
    {
        $model = $this->modelClass();

        return $model::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(actual) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                // Campuran satuan (rupiah & hitungan) -> total mentah, tanpa konversi juta.
                'total' => (float) $r->total,
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $tanggal): array
    {
        $model = $this->modelClass();

        return $model::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('kpi')
            ->get()
            ->map(fn ($m) => [
                'id_cabang' => $m->cabang_id,
                'id_uker' => $m->uker_id,
                'kpi' => $m->kpi,
                'tanggal' => $m->tanggal,
                'actual' => $m->actual,
            ])
            ->all();
    }

    public function hapusTanggal(string $tanggal): int
    {
        $model = $this->modelClass();

        return $model::query()->where('tanggal', Carbon::parse($tanggal)->toDateString())->delete();
    }

    public function hapusBulan(int $tahun, int $bulan): int
    {
        $model = $this->modelClass();
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return $model::query()
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()])
            ->delete();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function baca(string $path, string $namaBerkas): Collection
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $service = $this->serviceClass();
        $now = Carbon::now();

        $hasil = $baris->map(function (array $r, int $i) use ($ukerValid, $service, $now) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            $kode = $service::kanonikKpi((string) $r['kpi']);

            if ($kode === null) {
                throw ImportException::berkas(sprintf(
                    "Baris %d: KPI '%s' tidak dikenal. KPI yang valid: %s.",
                    $nomor,
                    trim((string) $r['kpi']),
                    implode(', ', array_column($service::KPI, 'label')),
                ));
            }

            return [
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'kpi' => $kode,
                'tanggal' => $this->tanggal($r['tanggal'], $nomor),
                'actual' => $this->angka($r['actual'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values();

        $this->tolakBarisKembar($hasil, $namaBerkas);

        return $hasil;
    }

    private function tanggal(mixed $nilai, int $nomor): string
    {
        $mentah = trim((string) $nilai);

        if ($mentah === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom tanggal kosong.");
        }

        try {
            return Carbon::parse($mentah)->toDateString();
        } catch (Throwable) {
            throw ImportException::berkas("Baris {$nomor}: tanggal '{$mentah}' tidak bisa dibaca.");
        }
    }

    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: actual '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function tolakBarisKembar(Collection $baris, string $namaBerkas): void
    {
        $kembar = $baris
            ->groupBy(fn (array $b) => implode('|', [$b['uker_id'], $b['kpi'], $b['tanggal']]))
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($kembar->isNotEmpty()) {
            throw ImportException::berkas(sprintf(
                '%s memuat %d kombinasi uker+kpi+tanggal yang kembar, contoh: %s. Gabungkan dulu baris kembar tersebut.',
                $namaBerkas,
                $kembar->count(),
                $kembar->keys()->take(3)->implode('; '),
            ));
        }
    }
}
