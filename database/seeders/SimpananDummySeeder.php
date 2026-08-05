<?php

namespace Database\Seeders;

use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\Uker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data DUMMY simpanan + RKA supaya dashboard langsung hidup di dev.
 *
 * BUKAN data sungguhan — jangan pernah dijalankan di produksi. Data asli masuk
 * lewat menu Admin > Upload Simpanan.
 *
 * Cakupan:
 *  - harian HARI KERJA selama 2 bulan (awal bulan lalu s/d hari ini)
 *  - 3 tanggal jangkar tambahan supaya delta MTD/YTD/YoY ada isinya:
 *    akhir Desember tahun lalu dan tanggal yang sama tahun lalu
 *  - RKA per uker x produk untuk seluruh bulan tahun berjalan
 */
class SimpananDummySeeder extends Seeder
{
    private const SEGMENTASI = ['Mikro', 'Ritel'];

    /** Bobot saldo relatif per produk. */
    private const BOBOT_PRODUK = [
        Simpanan::PRODUK_TABUNGAN => 1.0,
        Simpanan::PRODUK_GIRO => 0.35,
        Simpanan::PRODUK_DEPOSITO => 0.55,
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('SimpananDummySeeder dilewati: data dummy tidak boleh masuk produksi.');

            return;
        }

        $uker = Uker::query()
            ->tanpaRegionOffice()
            ->get(['id', 'cabang_id'])
            ->all();

        if ($uker === []) {
            $this->command?->warn('SimpananDummySeeder dilewati: master uker kosong, jalankan MasterSeeder dulu.');

            return;
        }

        $hariIni = Carbon::today();
        $tanggal = $this->tanggalSeed($hariIni);

        DB::table('simpanan')->delete();
        DB::table('rka_simpanan')->delete();

        $this->seedAktual($uker, $tanggal);
        $this->seedRka($uker, $hariIni->year);

        $this->command?->info(sprintf(
            'Simpanan dummy: %d baris aktual (%d tanggal x %d uker) + %d baris RKA.',
            Simpanan::query()->count(),
            count($tanggal),
            count($uker),
            RkaSimpanan::query()->count(),
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

        foreach ($uker as $u) {
            // Deterministik per uker: dasar saldo stabil antar-run.
            $dasar = 8_000_000_000 + (($u->id * 7_919) % 42_000_000_000);

            foreach ($tanggal as $i => $tgl) {
                // Tren naik pelan + riak harian, tetap deterministik.
                $tren = 1 + ($i * 0.0016);
                $riak = 1 + ((($u->id + $i * 31) % 17) - 8) / 400;

                foreach (self::BOBOT_PRODUK as $produk => $bobot) {
                    foreach (self::SEGMENTASI as $s => $segmentasi) {
                        $porsi = $s === 0 ? 0.6 : 0.4;

                        $buffer[] = [
                            'cabang_id' => $u->cabang_id,
                            'uker_id' => $u->id,
                            'produk' => $produk,
                            'segmentasi' => $segmentasi,
                            'tanggal' => $tgl->toDateString(),
                            'saldo' => round($dasar * $bobot * $porsi * $tren * $riak, 2),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($buffer) >= 2000) {
                            DB::table('simpanan')->insert($buffer);
                            $buffer = [];
                        }
                    }
                }
            }
        }

        if ($buffer !== []) {
            DB::table('simpanan')->insert($buffer);
        }
    }

    /**
     * @param  list<Uker>  $uker
     */
    private function seedRka(array $uker, int $tahun): void
    {
        $now = Carbon::now();
        $buffer = [];

        foreach ($uker as $u) {
            $dasar = 8_000_000_000 + (($u->id * 7_919) % 42_000_000_000);

            foreach (self::BOBOT_PRODUK as $produk => $bobot) {
                for ($bulan = 1; $bulan <= 12; $bulan++) {
                    // Target sedikit di atas/bawah aktual supaya pencapaian
                    // bervariasi (ada yang hijau, kuning, merah).
                    $faktor = 0.94 + ((($u->id + $bulan) % 13) / 100);

                    $buffer[] = [
                        'cabang_id' => $u->cabang_id,
                        'uker_id' => $u->id,
                        'produk' => $produk,
                        // Dummy memakai satu segmentasi saja; berkas RKA sungguhan
                        // membawa Ritel/Micro per uker.
                        'segmentasi' => self::SEGMENTASI[0],
                        'tahun' => $tahun,
                        'bulan' => $bulan,
                        'target' => round($dasar * $bobot * $faktor, 2),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($buffer) >= 2000) {
                        DB::table('rka_simpanan')->insert($buffer);
                        $buffer = [];
                    }
                }
            }
        }

        if ($buffer !== []) {
            DB::table('rka_simpanan')->insert($buffer);
        }
    }
}
