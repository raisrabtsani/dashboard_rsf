<?php

namespace Database\Seeders;

use App\Models\Pinjaman;
use App\Models\Region;
use App\Models\RkaPinjaman;
use App\Models\Uker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data DUMMY pinjaman + RKA supaya dashboard Pinjaman langsung hidup di dev.
 *
 * BUKAN data sungguhan — otomatis melewatkan diri di produksi.
 *
 * Berbeda dari dummy Simpanan: rollup Region 855 IKUT diisi untuk segmen
 * Menengah, meniru kenyataan bahwa segmen itu dikelola di level Region.
 */
class PinjamanDummySeeder extends Seeder
{
    private const SEGMENTASI = 'Ritel';

    /** Porsi baki debet per segmen terhadap dasar uker. */
    private const BOBOT_SEGMEN = [
        'Mikro' => 1.0,
        'Kecil' => 0.45,
        'Konsumer' => 0.30,
    ];

    /** Komposisi kualitas: mayoritas Lancar, sisanya SML & NPL. */
    private const BOBOT_KUALITAS = [
        Pinjaman::KUALITAS_LANCAR => 0.94,
        Pinjaman::KUALITAS_SML => 0.035,
        Pinjaman::KUALITAS_NPL => 0.025,
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('PinjamanDummySeeder dilewati: data dummy tidak boleh masuk produksi.');

            return;
        }

        $uker = Uker::query()->tanpaRegionOffice()->get(['id', 'cabang_id'])->all();

        if ($uker === []) {
            $this->command?->warn('PinjamanDummySeeder dilewati: master uker kosong.');

            return;
        }

        $hariIni = Carbon::today();
        $tanggal = $this->tanggalSeed($hariIni);

        DB::table('pinjaman')->delete();
        DB::table('rka_pinjaman')->delete();

        $this->seedAktual($uker, $tanggal);
        $this->seedRka($uker, $hariIni->year);

        $this->command?->info(sprintf(
            'Pinjaman dummy: %d baris aktual (%d tanggal) + %d baris RKA.',
            Pinjaman::query()->count(),
            count($tanggal),
            RkaPinjaman::query()->count(),
        ));
    }

    /**
     * @return list<Carbon>
     */
    private function tanggalSeed(Carbon $hariIni): array
    {
        $tanggal = [];
        $kursor = $hariIni->copy()->subMonthNoOverflow()->startOfMonth();

        while ($kursor->lessThanOrEqualTo($hariIni)) {
            if (! $kursor->isWeekend()) {
                $tanggal[] = $kursor->copy();
            }

            $kursor->addDay();
        }

        // Jangkar pembanding: akhir Des tahun lalu (YTD) & tanggal sama tahun lalu (YoY).
        return [
            $hariIni->copy()->subYear()->endOfYear(),
            $hariIni->copy()->subYear(),
            ...$tanggal,
        ];
    }

    /**
     * @param  list<Uker>  $uker
     * @param  list<Carbon>  $tanggal
     */
    private function seedAktual(array $uker, array $tanggal): void
    {
        $now = Carbon::now();
        $buffer = [];

        // Segmen Menengah dikelola Region: satu "uker" tambahan ber-id 855.
        $entitas = [...array_map(fn ($u) => [$u->id, $u->cabang_id, self::BOBOT_SEGMEN], $uker)];
        $entitas[] = [Region::OFFICE_ID, Region::OFFICE_ID, ['Menengah' => 6.0]];

        foreach ($entitas as [$ukerId, $cabangId, $bobotSegmen]) {
            $dasar = 5_000_000_000 + (($ukerId * 6_133) % 30_000_000_000);

            foreach ($tanggal as $i => $tgl) {
                $tren = 1 + ($i * 0.0014);
                $riak = 1 + ((($ukerId + $i * 23) % 15) - 7) / 500;

                foreach ($bobotSegmen as $segmen => $bobotS) {
                    foreach (self::BOBOT_KUALITAS as $kualitas => $bobotK) {
                        $buffer[] = [
                            'cabang_id' => $cabangId,
                            'uker_id' => $ukerId,
                            'segmen' => $segmen,
                            'segmentasi' => self::SEGMENTASI,
                            'kualitas' => $kualitas,
                            'tanggal' => $tgl->toDateString(),
                            'baki_debet' => round($dasar * $bobotS * $bobotK * $tren * $riak, 2),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($buffer) >= 2000) {
                            DB::table('pinjaman')->insert($buffer);
                            $buffer = [];
                        }
                    }
                }
            }
        }

        if ($buffer !== []) {
            DB::table('pinjaman')->insert($buffer);
        }
    }

    /**
     * @param  list<Uker>  $uker
     */
    private function seedRka(array $uker, int $tahun): void
    {
        $now = Carbon::now();
        $buffer = [];

        $entitas = [...array_map(fn ($u) => [$u->id, $u->cabang_id, self::BOBOT_SEGMEN], $uker)];
        $entitas[] = [Region::OFFICE_ID, Region::OFFICE_ID, ['Menengah' => 6.0]];

        foreach ($entitas as [$ukerId, $cabangId, $bobotSegmen]) {
            $dasar = 5_000_000_000 + (($ukerId * 6_133) % 30_000_000_000);

            foreach ($bobotSegmen as $segmen => $bobotS) {
                foreach (self::BOBOT_KUALITAS as $kualitas => $bobotK) {
                    for ($bulan = 1; $bulan <= 12; $bulan++) {
                        // Target SML/NPL sengaja lebih KETAT dari realisasi
                        // (faktor < 1) supaya pencapaian tab inverse bervariasi.
                        $faktor = $kualitas === Pinjaman::KUALITAS_LANCAR
                            ? 0.95 + ((($ukerId + $bulan) % 11) / 100)
                            : 0.88 + ((($ukerId + $bulan) % 25) / 100);

                        $buffer[] = [
                            'cabang_id' => $cabangId,
                            'uker_id' => $ukerId,
                            'segmen' => $segmen,
                            'segmentasi' => self::SEGMENTASI,
                            'kualitas' => $kualitas,
                            'tahun' => $tahun,
                            'bulan' => $bulan,
                            'target' => round($dasar * $bobotS * $bobotK * $faktor, 2),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($buffer) >= 2000) {
                            DB::table('rka_pinjaman')->insert($buffer);
                            $buffer = [];
                        }
                    }
                }
            }
        }

        if ($buffer !== []) {
            DB::table('rka_pinjaman')->insert($buffer);
        }
    }
}
