<?php

namespace Tests\Feature\Admin;

use App\Models\Recovery;
use App\Models\RkaRecovery;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecoveryImportTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    private const UKER = 5438;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Berkas sementara tanpa ekstensi asli — meniru unggahan HTTP sungguhan. */
    private function berkas(string $isi, string $namaAsli = 'recovery.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaAsli, 'text/csv', null, true);
    }

    private function unggah(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/upload/recovery', ['berkas' => $this->berkas($isi)]);
    }

    private function unggahRka(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/rka/recovery', ['berkas' => $this->berkas($isi, 'rka-recovery.csv')]);
    }

    private const HEADER = "id_cabang,id_uker,segmen,tanggal,actual\n";

    // --- ATURAN INTI: SUM sebelum simpan ---------------------------------

    /**
     * Persyaratan utama domain Recovery: berkas berisi banyak baris per
     * kombinasi (satu per debitur). 3 baris dengan segmen & tanggal sama harus
     * menjadi SATU baris berisi JUMLAH ketiganya — bukan last-wins, bukan MAX.
     */
    public function test_tiga_baris_segmen_dan_tanggal_sama_dijumlahkan_jadi_satu(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,100000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,250000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,150000000\n";

        $respons = $this->unggah($isi)->assertOk();

        // Satu baris tersimpan, nilainya jumlah ketiganya (100+250+150 = 500 jt).
        $this->assertSame(1, Recovery::query()->count());
        $this->assertSame(500_000_000.0, (float) Recovery::query()->sum('actual'));

        $baris = Recovery::query()->sole();
        $this->assertSame(500_000_000.0, (float) $baris->actual);
        $this->assertSame('Micro', $baris->segmen);
        $this->assertSame('2026-06-18', $baris->tanggal);

        // Ringkasan melaporkan 3 baris sumber -> 1 baris tersimpan.
        $this->assertSame(3, $respons->json('hasil.sumber'));
        $this->assertSame(1, $respons->json('hasil.baris'));
        $this->assertSame(500_000_000.0, (float) $respons->json('hasil.total'));
    }

    public function test_bukan_last_wins_atau_max(): void
    {
        // Kalau importer keliru pakai last-wins hasilnya 150 jt; kalau MAX 250 jt.
        // Yang benar SUM = 500 jt.
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,100000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,250000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,150000000\n";

        $this->unggah($isi)->assertOk();

        $nilai = (float) Recovery::query()->value('actual');
        $this->assertNotSame(150_000_000.0, $nilai, 'Tidak boleh last-wins.');
        $this->assertNotSame(250_000_000.0, $nilai, 'Tidak boleh MAX.');
        $this->assertSame(500_000_000.0, $nilai);
    }

    public function test_upload_ulang_bersifat_idempoten_bukan_menggandakan(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,300000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,200000000\n";

        $this->unggah($isi)->assertOk();
        // Upsert pada recovery_unique: unggah ulang berkas yang sama = menimpa
        // dengan jumlah yang sama, tetap 1 baris 500 jt (bukan 1.000 jt).
        $this->unggah($isi)->assertOk();

        $this->assertSame(1, Recovery::query()->count());
        $this->assertSame(500_000_000.0, (float) Recovery::query()->sum('actual'));
    }

    public function test_segmen_berbeda_pada_tanggal_sama_hidup_berdampingan(): void
    {
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026-06-18,100000000\n")->assertOk();
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",SME,2026-06-18,200000000\n")->assertOk();

        $this->assertSame(2, Recovery::query()->count());
        $this->assertEqualsCanonicalizing(
            ['Micro', 'SME'],
            Recovery::query()->distinct()->pluck('segmen')->all(),
        );
    }

    public function test_segmen_disimpan_mentah_apa_adanya(): void
    {
        // "Small" dan "Medium" TIDAK dinormalkan saat import — disimpan mentah.
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Small,2026-06-18,100000000\n"
            .self::CABANG.','.self::UKER.",Medium,2026-06-18,100000000\n";

        $this->unggah($isi)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Small', 'Medium'],
            Recovery::query()->distinct()->pluck('segmen')->all(),
        );
    }

    public function test_uker_tak_dikenal_ditolak(): void
    {
        $isi = self::HEADER.self::CABANG.",999999,Micro,2026-06-18,100000000\n";

        $this->unggah($isi)->assertStatus(422);
        $this->assertSame(0, Recovery::query()->count());
    }

    public function test_segmen_kosong_ditolak(): void
    {
        $isi = self::HEADER.self::CABANG.','.self::UKER.",,2026-06-18,100000000\n";

        $this->unggah($isi)->assertStatus(422);
        $this->assertSame(0, Recovery::query()->count());
    }

    public function test_actual_bukan_angka_menggagalkan_seluruh_berkas(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,100000000\n"
            .self::CABANG.','.self::UKER.",SME,2026-06-18,bukan-angka\n";

        $this->unggah($isi)->assertStatus(422);
        // Transaksi: tidak boleh ada baris yang lolos duluan.
        $this->assertSame(0, Recovery::query()->count());
    }

    public function test_riwayat_unduh_dan_hapus(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,300000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026-06-18,200000000\n"
            .self::CABANG.','.self::UKER.",SME,2026-06-18,500000000\n";

        $this->unggah($isi)->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/recovery/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertSame('2026-06-18', $riwayat[0]['tanggal']);
        // 2 baris tersimpan (Micro digabung + SME), total 1.000 jt.
        $this->assertSame(2, $riwayat[0]['jumlah_baris']);
        $this->assertSame(1_000.0, (float) $riwayat[0]['total']);

        $isiUnduh = $this->actingAs($this->admin())
            ->get('/admin/upload/recovery/unduh/2026-06-18')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('159,5438,Micro,2026-06-18', $isiUnduh);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/recovery/2026-06-18')
            ->assertOk();

        $this->assertSame(0, Recovery::query()->count());
    }

    public function test_hapus_per_bulan(): void
    {
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026-06-18,100000000\n")->assertOk();
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026-07-01,100000000\n")->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/recovery/bulk-month', ['tahun' => 2026, 'bulan' => 6])
            ->assertOk();

        $this->assertSame(1, Recovery::query()->count());
        $this->assertSame('2026-07-01', Recovery::query()->value('tanggal'));
    }

    // --- RKA ---------------------------------------------------------------

    public function test_rka_menerima_nama_bulan_dan_kolom_kapital(): void
    {
        $isi = "id_cabang,id_uker,Segmen,Tahun,Bulan, RKA \n"
            .self::CABANG.','.self::UKER.',SME,2026,Juni," 100,000,000 "'."\n";

        $this->unggahRka($isi)->assertOk();

        $baris = RkaRecovery::query()->sole();
        $this->assertSame(6, $baris->bulan);
        $this->assertSame('SME', $baris->segmen);
        $this->assertSame(100_000_000.0, (float) $baris->target);
    }

    public function test_rka_ditimpa_bukan_digandakan(): void
    {
        $buat = fn (int $target) => "id_cabang,id_uker,segmen,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,{$target}\n";

        $this->unggahRka($buat(100_000_000))->assertOk();
        $this->unggahRka($buat(200_000_000))->assertOk();

        $this->assertSame(1, RkaRecovery::query()->count());
        $this->assertSame(200_000_000.0, (float) RkaRecovery::query()->value('target'));
    }

    public function test_rka_melewati_target_kosong(): void
    {
        $isi = "id_cabang,id_uker,segmen,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Micro,2026,6,\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,100000000\n";

        $respons = $this->unggahRka($isi)->assertOk();

        $this->assertSame(1, RkaRecovery::query()->count());
        $this->assertSame(1, $respons->json('hasil.dilewati'));
    }

    public function test_rka_target_dijumlahkan_per_id_uker_bulan_dan_tahun_yang_sama(): void
    {
        $isi = "id_cabang,id_uker,segmen,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,100000000\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,200000000\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,50000000\n";

        $respons = $this->unggahRka($isi)->assertOk();

        $this->assertSame(1, RkaRecovery::query()->count());
        $this->assertSame(350_000_000.0, (float) RkaRecovery::query()->value('target'));
        $this->assertSame(3, $respons->json('hasil.sumber'));
        $this->assertSame(1, $respons->json('hasil.baris'));
        $this->assertSame(['id_uker', 'bulan', 'tahun'], $respons->json('hasil.sumif.kriteria'));
        $this->assertSame(1, $respons->json('hasil.sumif.kombinasi'));
        $this->assertSame(2, $respons->json('hasil.sumif.baris_tergabung'));
        $this->assertSame(350_000_000.0, (float) $respons->json('hasil.sumif.total_sumber'));
        $this->assertSame(350_000_000.0, (float) $respons->json('hasil.sumif.total_hasil'));
    }

    public function test_rka_sumif_tetap_mempertahankan_rincian_segmen(): void
    {
        $isi = "id_cabang,id_uker,segmen,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Micro,2026,6,100000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026,6,50000000\n"
            .self::CABANG.','.self::UKER.",SME,2026,6,200000000\n";

        $respons = $this->unggahRka($isi)->assertOk();

        // SUMIF utama hanya memiliki satu kombinasi uker+bulan+tahun, tetapi
        // rincian Micro dan SME tetap hidup agar filter segmen dashboard aman.
        $this->assertSame(1, $respons->json('hasil.sumif.kombinasi'));
        $this->assertSame(2, RkaRecovery::query()->count());
        $this->assertSame(
            150_000_000.0,
            (float) RkaRecovery::query()->where('segmen', 'Micro')->value('target'),
        );
        $this->assertSame(
            200_000_000.0,
            (float) RkaRecovery::query()->where('segmen', 'SME')->value('target'),
        );
        $this->assertSame(350_000_000.0, (float) RkaRecovery::query()->sum('target'));
    }

    public function test_hapus_rka_per_tahun(): void
    {
        RkaRecovery::factory()->create(['tahun' => 2025, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG]);
        RkaRecovery::factory()->create(['tahun' => 2026, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG, 'bulan' => 2]);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/rka/recovery/year/2025')
            ->assertOk();

        $this->assertSame(1, RkaRecovery::query()->count());
    }

    public function test_non_admin_ditolak(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/recovery')->assertForbidden();
        $this->actingAs($ro)->get('/admin/rka/recovery')->assertForbidden();
        $this->actingAs($ro)
            ->post('/admin/upload/recovery', ['berkas' => $this->berkas(self::HEADER)])
            ->assertForbidden();
    }
}
