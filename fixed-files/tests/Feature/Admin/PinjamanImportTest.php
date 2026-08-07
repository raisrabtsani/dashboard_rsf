<?php

namespace Tests\Feature\Admin;

use App\Models\Pinjaman;
use App\Models\RkaPinjaman;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PinjamanImportTest extends TestCase
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
    private function berkas(string $isi, string $namaAsli = 'pinjaman.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaAsli, 'text/csv', null, true);
    }

    private function unggah(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/upload/pinjaman', ['berkas' => $this->berkas($isi)]);
    }

    private function unggahRka(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/rka/pinjaman', ['berkas' => $this->berkas($isi, 'rka-pinjaman.csv')]);
    }

    private const HEADER = "id_cabang,id_uker,segmen,segmentasi,kualitas,tanggal,baki_debet\n";

    /** 3 kualitas segmen Mikro, total 1.000.000.000. */
    private function isiMikro(string $tanggal = '2026-06-18'): string
    {
        return self::HEADER
            .self::CABANG.','.self::UKER.",Mikro,Ritel,Lancar,{$tanggal},800000000\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,SML,{$tanggal},120000000\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,NPL,{$tanggal},80000000\n";
    }

    public function test_upload_menghasilkan_jumlah_baris_dan_total_yang_sesuai(): void
    {
        $this->unggah($this->isiMikro())->assertOk();

        $this->assertSame(3, Pinjaman::query()->count());
        $this->assertSame(1_000_000_000.0, (float) Pinjaman::query()->sum('baki_debet'));
    }


    public function test_preview_menerima_csv_utf16_tableau_dengan_header_tanggal_terpisah(): void
    {
        $isiUtf8 = "id_cabang,id_uker,SEGMEN_2025,Segmentasi,Kualitas Kredit, Baki Debet ,Month, Day, Year of Posisi\r\n"
            .self::CABANG.','.self::UKER.",Micro,,Lancar,100000000,01/31/2026\r\n";
        $isiUtf16 = "\xFF\xFE".iconv('UTF-8', 'UTF-16LE', $isiUtf8);

        $respons = $this->actingAs($this->admin())
            ->post('/admin/upload/pinjaman/preview', [
                'berkas' => $this->berkas($isiUtf16, 'SSA_Pinjaman_Non Commersial.csv'),
            ]);

        $respons->assertOk()
            ->assertJsonPath('hasil.laporan.valid', 1)
            ->assertJsonPath('hasil.laporan.tidak_valid', 0)
            ->assertJsonPath('hasil.baris', 1);
    }

    public function test_upload_menjumlahkan_baris_sumber_dengan_kunci_yang_sama(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Small,,NPL,2026-06-18,100000000\n"
            .self::CABANG.','.self::UKER.",Small,,NPL,2026-06-18,250000000\n";

        $respons = $this->unggah($isi)->assertOk();

        $respons->assertJsonPath('hasil.baris_sumber', 2)
            ->assertJsonPath('hasil.baris', 1);

        $this->assertSame(1, Pinjaman::query()->count());
        $this->assertSame('Tanpa Segmentasi', Pinjaman::query()->value('segmentasi'));
        $this->assertSame(350_000_000.0, (float) Pinjaman::query()->value('baki_debet'));
    }

    public function test_kualitas_tak_dikenal_ditolak(): void
    {
        $isi = self::HEADER.self::CABANG.','.self::UKER.",Mikro,Ritel,Macet,2026-06-18,1000000\n";

        $this->unggah($isi)->assertStatus(422);
        $this->assertSame(0, Pinjaman::query()->count());
    }

    // --- Aturan duplikat per (tanggal + segmen) ---------------------------

    public function test_segmen_baru_pada_tanggal_yang_sudah_ada_tetap_bisa_masuk(): void
    {
        $this->unggah($this->isiMikro())->assertOk();

        // Segmen Menengah menyusul untuk TANGGAL YANG SAMA.
        $menengah = self::HEADER
            .self::CABANG.','.self::UKER.",Menengah,Ritel,Lancar,2026-06-18,500000000\n";

        $respons = $this->unggah($menengah)->assertOk();

        $this->assertSame(4, Pinjaman::query()->count());
        $this->assertSame(0, $respons->json('hasil.dilewati'));
        $this->assertEqualsCanonicalizing(
            ['Mikro', 'Menengah'],
            Pinjaman::query()->distinct()->pluck('segmen')->all(),
        );
    }

    public function test_segmen_yang_sudah_ada_dilewati_bukan_menggandakan(): void
    {
        $this->unggah($this->isiMikro())->assertOk();

        // Berkas campuran: Mikro sudah ada, Kecil belum.
        $campur = $this->isiMikro()
            .self::CABANG.','.self::UKER.",Kecil,Ritel,Lancar,2026-06-18,300000000\n";

        $respons = $this->unggah($campur)->assertOk();

        // 3 baris Mikro dilewati, 1 baris Kecil masuk.
        $this->assertSame(1, $respons->json('hasil.baris'));
        $this->assertSame(3, $respons->json('hasil.dilewati'));
        $this->assertSame(4, Pinjaman::query()->count());
    }

    public function test_upload_ulang_seluruhnya_sama_ditolak_409(): void
    {
        $this->unggah($this->isiMikro())->assertOk();

        $respons = $this->unggah($this->isiMikro());

        // 409 = bentrok data (bukan 422 yang berarti berkasnya salah).
        $respons->assertStatus(409);
        $this->assertSame(3, Pinjaman::query()->count());
    }

    public function test_riwayat_unduh_dan_hapus(): void
    {
        $this->unggah($this->isiMikro())->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/pinjaman/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertSame('2026-06-18', $riwayat[0]['tanggal']);
        $this->assertSame(3, $riwayat[0]['jumlah_baris']);
        $this->assertSame(1_000.0, (float) $riwayat[0]['total']);

        $isi = $this->actingAs($this->admin())
            ->get('/admin/upload/pinjaman/unduh/2026-06-18')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('159,5438,Mikro,Ritel,Lancar,2026-06-18', $isi);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/pinjaman/2026-06-18')
            ->assertOk();

        $this->assertSame(0, Pinjaman::query()->count());
    }

    public function test_hapus_per_bulan(): void
    {
        $this->unggah($this->isiMikro('2026-06-18'))->assertOk();
        $this->unggah($this->isiMikro('2026-07-01'))->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/pinjaman/bulk-month', ['tahun' => 2026, 'bulan' => 6])
            ->assertOk();

        $this->assertSame(3, Pinjaman::query()->count());
        $this->assertSame('2026-07-01', Pinjaman::query()->value('tanggal'));
    }

    // --- RKA ---------------------------------------------------------------

    public function test_rka_menerima_nama_bulan_dan_kolom_kapital(): void
    {
        $isi = "id_cabang,id_uker,Segmen,Segmentasi,Kualitas,Tahun,Bulan, RKA \n"
            .self::CABANG.','.self::UKER.',Mikro,Ritel,NPL,2026,Juni," 100,000,000 "'."\n";

        $this->unggahRka($isi)->assertOk();

        $baris = RkaPinjaman::query()->sole();

        $this->assertSame(6, $baris->bulan);
        $this->assertSame('NPL', $baris->kualitas);
        $this->assertSame(100_000_000.0, (float) $baris->target);
    }

    public function test_rka_melewati_target_kosong(): void
    {
        $isi = "id_cabang,id_uker,segmen,segmentasi,kualitas,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,SML,2026,Juni,\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,NPL,2026,Juni,100000000\n";

        $respons = $this->unggahRka($isi)->assertOk();

        $this->assertSame(1, RkaPinjaman::query()->count());
        $this->assertSame(1, $respons->json('hasil.dilewati'));
    }

    public function test_rka_ditimpa_bukan_digandakan(): void
    {
        $buat = fn (int $target) => "id_cabang,id_uker,segmen,segmentasi,kualitas,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,NPL,2026,6,{$target}\n";

        $this->unggahRka($buat(100_000_000))->assertOk();
        $this->unggahRka($buat(200_000_000))->assertOk();

        $this->assertSame(1, RkaPinjaman::query()->count());
        $this->assertSame(200_000_000.0, (float) RkaPinjaman::query()->value('target'));
    }

    public function test_rka_membedakan_kualitas_pada_kunci_unik(): void
    {
        $isi = "id_cabang,id_uker,segmen,segmentasi,kualitas,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,SML,2026,6,100000000\n"
            .self::CABANG.','.self::UKER.",Mikro,Ritel,NPL,2026,6,200000000\n";

        $this->unggahRka($isi)->assertOk();

        // Dua kualitas berbeda tidak boleh saling menimpa.
        $this->assertSame(2, RkaPinjaman::query()->count());
        $this->assertSame(300_000_000.0, (float) RkaPinjaman::query()->sum('target'));
    }

    public function test_hapus_rka_per_tahun(): void
    {
        RkaPinjaman::factory()->create(['tahun' => 2025, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG]);
        RkaPinjaman::factory()->create(['tahun' => 2026, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG]);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/rka/pinjaman/year/2025')
            ->assertOk();

        $this->assertSame(1, RkaPinjaman::query()->count());
    }

    public function test_non_admin_ditolak(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/pinjaman')->assertForbidden();
        $this->actingAs($ro)->get('/admin/rka/pinjaman')->assertForbidden();
        $this->actingAs($ro)
            ->post('/admin/upload/pinjaman', ['berkas' => $this->berkas($this->isiMikro())])
            ->assertForbidden();
    }
}
