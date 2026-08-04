<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\Uker;
use App\Support\Csv;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Seeder master organisasi: region -> cabang -> uker, plus dimensi area.
 *
 * SELURUH isi tabel dibaca dari CSV di database/seeders/data/ — dilarang
 * meng-hardcode baris master di sini. Kalau ada cabang/uker baru, ganti CSV-nya
 * lalu jalankan ulang seeder; jangan menambahkan array di dalam kode.
 *
 * Idempoten: memakai upsert dengan primary key manual, jadi aman dijalankan
 * berulang tanpa truncate dan tanpa menggandakan baris.
 */
class MasterSeeder extends Seeder
{
    private const FILE_REGION = 'code_region.csv';

    private const FILE_UKER = 'code_uker.csv';

    private const FILE_AREA = 'peta_area.csv';

    public function run(): void
    {
        $region = $this->bacaCsv(self::FILE_REGION, ['id_region', 'Nama Region']);
        $peta = $this->bacaCsv(self::FILE_AREA, ['id_region', 'id_area', 'id_cabang', 'Nama Area']);
        $uker = $this->bacaCsv(self::FILE_UKER, ['id_cabang', 'id_uker', 'Nama Cabang', 'Nama Uker']);

        $this->seedRegion($region);
        $this->seedAreas($peta);
        $this->seedCabang($uker, $peta, $region);
        $this->seedUker($uker);
        $this->seedRegionOffice($region);

        $this->command?->info(sprintf(
            'Master organisasi: %d region, %d area, %d cabang, %d uker (termasuk rollup %d).',
            Region::query()->count(),
            Area::query()->count(),
            Cabang::query()->count(),
            Uker::query()->count(),
            Region::OFFICE_ID,
        ));
    }

    private function seedRegion(Collection $region): void
    {
        $baris = $region->map(fn (array $r) => [
            'id' => (int) $r['id_region'],
            'nama' => trim($r['Nama Region']),
        ]);

        $this->simpan(Region::query(), $baris, ['nama']);
    }

    /**
     * Area diambil dari pasangan unik (id_area, Nama Area) di peta_area.csv.
     */
    private function seedAreas(Collection $peta): void
    {
        $baris = $peta
            ->map(fn (array $r) => [
                'id' => (int) $r['id_area'],
                'nama' => trim($r['Nama Area']),
            ])
            ->keyBy('id')
            ->values();

        $this->simpan(Area::query(), $baris, ['nama']);
    }

    /**
     * Satu baris cabang per id_cabang unik di code_uker.csv.
     *
     * area_id di-join lewat id_cabang di peta_area.csv; BO yang belum punya
     * pemetaan area tetap masuk dengan area_id = null (bukan dilewati).
     */
    private function seedCabang(Collection $uker, Collection $peta, Collection $region): void
    {
        $areaPerCabang = $peta->mapWithKeys(fn (array $r) => [(int) $r['id_cabang'] => (int) $r['id_area']]);
        $regionPerCabang = $peta->mapWithKeys(fn (array $r) => [(int) $r['id_cabang'] => (int) $r['id_region']]);

        // code_region.csv tidak punya kolom id_cabang, jadi pemetaan per-cabang
        // diambil dari peta_area.csv; region pertama dipakai sebagai cadangan
        // untuk BO yang belum terdaftar di peta_area.csv.
        $regionDefault = (int) $region->first()['id_region'];

        $baris = $uker
            ->map(fn (array $r) => [
                'id' => (int) $r['id_cabang'],
                'nama' => trim($r['Nama Cabang']),
            ])
            ->keyBy('id')
            ->map(fn (array $c) => [
                'id' => $c['id'],
                'region_id' => $regionPerCabang->get($c['id'], $regionDefault),
                'area_id' => $areaPerCabang->get($c['id']),
                'nama' => $c['nama'],
            ])
            ->values();

        $tanpaArea = $baris->whereNull('area_id');

        if ($tanpaArea->isNotEmpty()) {
            $this->command?->warn(sprintf(
                '%d cabang belum punya area di %s: %s',
                $tanpaArea->count(),
                self::FILE_AREA,
                $tanpaArea->pluck('id')->implode(', '),
            ));
        }

        $this->simpan(Cabang::query(), $baris, ['region_id', 'area_id', 'nama']);
    }

    /**
     * Satu baris uker per baris code_uker.csv; tipe diturunkan dari id & nama.
     */
    private function seedUker(Collection $uker): void
    {
        $takDikenal = collect();

        $baris = $uker->map(function (array $r) use ($takDikenal) {
            $idUker = (int) $r['id_uker'];
            $idCabang = (int) $r['id_cabang'];
            $nama = trim($r['Nama Uker']);
            $tipe = Uker::tipeDari($idUker, $idCabang, $nama);

            if ($tipe === null) {
                $takDikenal->push("{$idUker} ({$nama})");
                $tipe = Uker::TIPE_UNIT;
            }

            return [
                'id' => $idUker,
                'cabang_id' => $idCabang,
                'nama' => $nama,
                'tipe' => $tipe,
            ];
        })->keyBy('id')->values();

        if ($takDikenal->isNotEmpty()) {
            $this->command?->warn(sprintf(
                'Tipe uker tak dikenali dari namanya, dianggap %s: %s',
                Uker::TIPE_UNIT,
                $takDikenal->implode('; '),
            ));
        }

        $this->simpan(Uker::query(), $baris, ['cabang_id', 'nama', 'tipe']);
    }

    /**
     * Baris bayangan rollup Region Office (855).
     *
     * 855 sengaja TIDAK ada di code_uker.csv karena bukan kantor operasional,
     * tapi data kelolaan level Region (mis. Pinjaman segmen Medium) memakai
     * cabang_id/uker_id 855. Tanpa baris master ini, import tersebut gagal
     * validasi foreign key. Baris ini disembunyikan dari tampilan lewat scope
     * `tanpaRegionOffice()`.
     */
    private function seedRegionOffice(Collection $region): void
    {
        $baris = $region->firstWhere(fn (array $r) => (int) $r['id_region'] === Region::OFFICE_ID);

        if ($baris === null) {
            throw new RuntimeException(sprintf(
                'Region rollup %d tidak ada di %s — baris master cabang & uker %d tidak bisa dibuat.',
                Region::OFFICE_ID,
                self::FILE_REGION,
                Region::OFFICE_ID,
            ));
        }

        $nama = trim($baris['Nama Region']);

        $this->simpan(
            Cabang::query(),
            collect([[
                'id' => Region::OFFICE_ID,
                'region_id' => Region::OFFICE_ID,
                'area_id' => null,
                'nama' => $nama,
            ]]),
            ['region_id', 'area_id', 'nama'],
        );

        $this->simpan(
            Uker::query(),
            collect([[
                'id' => Region::OFFICE_ID,
                'cabang_id' => Region::OFFICE_ID,
                'nama' => $nama,
                'tipe' => Uker::TIPE_REGION,
            ]]),
            ['cabang_id', 'nama', 'tipe'],
        );
    }

    /**
     * Upsert per potongan 500 baris, dengan timestamps diisi manual karena
     * upsert() melewati event model.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  Collection<int, array<string, mixed>>  $baris
     * @param  list<string>  $perbarui
     */
    private function simpan($query, Collection $baris, array $perbarui): void
    {
        $now = Carbon::now();

        $baris
            ->map(fn (array $b) => $b + ['created_at' => $now, 'updated_at' => $now])
            ->chunk(500)
            ->each(fn (Collection $potongan) => $query->clone()->upsert(
                $potongan->values()->all(),
                ['id'],
                [...$perbarui, 'updated_at'],
            ));
    }

    /**
     * @param  list<string>  $kolomWajib
     * @return Collection<int, array<string, string>>
     */
    private function bacaCsv(string $namaFile, array $kolomWajib): Collection
    {
        return Csv::baca(__DIR__.'/data/'.$namaFile, $kolomWajib);
    }
}
