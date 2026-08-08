<?php

namespace Tests\Feature\Admin;

use App\Models\Edc;
use App\Models\RkaEdc;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MerchantImportTest extends TestCase
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

    private function berkas(string $isi, string $namaAsli = 'edc.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaAsli, 'text/csv', null, true);
    }

    private function unggah(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/upload/edc', ['berkas' => $this->berkas($isi)]);
    }

    private function unggahRka(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/rka/edc', ['berkas' => $this->berkas($isi, 'rka-edc.csv')]);
    }

    private const HEADER = "id_cabang,id_uker,kpi,tanggal,actual\n";

    // --- Normalisasi nama KPI (inti importer) ----------------------------

    public function test_nama_kpi_dinormalkan_ke_kode_kanonik(): void
    {
        // Nama mentah dengan spasi/kapital berbeda-beda.
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",TID,2026-03-10,100\n"
            .self::CABANG.','.self::UKER.",MID,2026-03-10,20\n"
            .self::CABANG.','.self::UKER.",Sales_Volume_Marginal,2026-03-10,50000000\n"
            .self::CABANG.','.self::UKER.",SV_RP_Nol,2026-03-10,3\n"
            .self::CABANG.','.self::UKER.",Jml_Trx_Marginal,2026-03-10,450\n";

        $this->unggah($isi)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['TID', 'MID', 'SALES_VOLUME', 'EDC_SV_0', 'JUMLAH_TRX'],
            Edc::query()->distinct()->pluck('kpi')->all(),
        );
    }

    public function test_kpi_tak_dikenal_ditolak(): void
    {
        $isi = self::HEADER.self::CABANG.','.self::UKER.",KPI Ngawur,2026-03-10,100\n";

        $respons = $this->unggah($isi);
        $respons->assertStatus(422);
        $this->assertStringContainsString('tidak dikenal', $respons->json('message'));
        $this->assertSame(0, Edc::query()->count());
    }

    public function test_upsert_idempoten_bukan_menggandakan(): void
    {
        $isi = self::HEADER.self::CABANG.','.self::UKER.",TID,2026-03-10,100\n";

        $this->unggah($isi)->assertOk();
        $this->unggah($isi)->assertOk();

        $this->assertSame(1, Edc::query()->count());
        $this->assertSame(100.0, (float) Edc::query()->value('actual'));
    }

    public function test_upload_menimpa_nilai_lama(): void
    {
        $buat = fn (int $v) => self::HEADER.self::CABANG.','.self::UKER.",TID,2026-03-10,{$v}\n";

        $this->unggah($buat(100))->assertOk();
        $this->unggah($buat(250))->assertOk();

        $this->assertSame(1, Edc::query()->count());
        $this->assertSame(250.0, (float) Edc::query()->value('actual'));
    }

    public function test_baris_kembar_ditolak(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",TID,2026-03-10,100\n"
            .self::CABANG.','.self::UKER.",Terminal ID,2026-03-10,200\n";

        // "TID" dan "Terminal ID" dinormalkan ke kode yang sama -> kembar.
        $respons = $this->unggah($isi);
        $respons->assertStatus(422);
        $this->assertStringContainsString('kembar', $respons->json('message'));
        $this->assertSame(0, Edc::query()->count());
    }

    public function test_riwayat_unduh_dan_hapus(): void
    {
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",TID,2026-03-10,100\n")->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/edc/riwayat')
            ->assertOk()
            ->json('riwayat');
        $this->assertSame('2026-03-10', $riwayat[0]['tanggal']);

        $isi = $this->actingAs($this->admin())
            ->get('/admin/upload/edc/unduh/2026-03-10')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('159,5438,TID,2026-03-10', $isi);

        $this->actingAs($this->admin())->deleteJson('/admin/upload/edc/2026-03-10')->assertOk();
        $this->assertSame(0, Edc::query()->count());
    }

    // --- RKA ---------------------------------------------------------------

    public function test_rka_menormalkan_kpi_dan_upsert(): void
    {
        $isi = "id_cabang,id_uker,KPI,Tahun,Bulan, RKA \n"
            .self::CABANG.','.self::UKER.',"Sales Volume",2026,Maret," 200,000,000 "'."\n";

        $this->unggahRka($isi)->assertOk();

        $baris = RkaEdc::query()->sole();
        $this->assertSame('SALES_VOLUME', $baris->kpi);
        $this->assertSame(3, $baris->bulan);
        $this->assertSame(200_000_000.0, (float) $baris->target);
    }

    public function test_rka_edc_sales_volume_target_nol_tetap_sales_volume(): void
    {
        $isi = "id_cabang,id_uker,kpi,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Sales_Volume_Marginal,2026,3,0\n"
            .self::CABANG.','.self::UKER.",EDC_Produktif,2026,3,250\n"
            .self::CABANG.','.self::UKER.",TID,2026,3,300\n"
            .self::CABANG.','.self::UKER.",MID,2026,3,200\n";

        $this->unggahRka($isi)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['SALES_VOLUME', 'EDC_PRODUKTIF', 'TID', 'MID'],
            RkaEdc::query()->pluck('kpi')->all(),
        );
        $this->assertSame(
            0.0,
            (float) RkaEdc::query()->where('kpi', 'SALES_VOLUME')->value('target'),
        );
        $this->assertSame(0, RkaEdc::query()->where('kpi', 'EDC_SV_0')->count());
    }

    public function test_rka_edc_menolak_kpi_edc_sv_nol(): void
    {
        $isi = "id_cabang,id_uker,kpi,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",SV_RP_Nol,2026,3,10\n";

        $respons = $this->unggahRka($isi);
        $respons->assertStatus(422);
        $this->assertStringContainsString('tidak dikenal', $respons->json('message'));
        $this->assertSame(0, RkaEdc::query()->count());
    }

    public function test_upload_qris_menormalkan_nama_kpi_actual(): void
    {
        $isi = "id_cabang,id_uker,KPI,Actual,Posisi\n"
            .self::CABANG.','.self::UKER.",Sales Volume Marginal,1000000,07/31/2026\n"
            .self::CABANG.','.self::UKER.",Jml QRIS,500,07/31/2026\n"
            .self::CABANG.','.self::UKER.",Jml Trx Marginal,2500,07/31/2026\n"
            .self::CABANG.','.self::UKER.",Qris Produktif,300,07/31/2026\n";

        $this->actingAs($this->admin())
            ->post('/admin/upload/qris', ['berkas' => $this->berkas($isi, 'actual-qris.csv')])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['SALES_VOLUME', 'USER_QRIS', 'JUMLAH_TRX', 'QRIS_PRODUKTIF'],
            \App\Models\Qris::query()->pluck('kpi')->all(),
        );
    }

    public function test_rka_qris_endpoint_terpisah(): void
    {
        // Sub-domain QRIS punya endpoint & tabel sendiri.
        $isi = "id_cabang,id_uker,kpi,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",User QRIS,2026,3,500\n";

        $this->actingAs($this->admin())
            ->post('/admin/rka/qris', ['berkas' => $this->berkas($isi, 'rka-qris.csv')])
            ->assertOk();

        $this->assertSame(0, RkaEdc::query()->count());
        $this->assertSame(1, \App\Models\RkaQris::query()->count());
        $this->assertSame('USER_QRIS', \App\Models\RkaQris::query()->value('kpi'));
    }

    public function test_rka_qris_menormalkan_nama_kpi_sumber(): void
    {
        $isi = "id_cabang,id_uker,kpi,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Sales_Volume_QRIS,2026,3,1000000\n"
            .self::CABANG.','.self::UKER.",Jml Qris,2026,3,500\n"
            .self::CABANG.','.self::UKER.",Qris Produktif,2026,3,300\n";

        $this->actingAs($this->admin())
            ->post('/admin/rka/qris', ['berkas' => $this->berkas($isi, 'rka-qris.csv')])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['SALES_VOLUME', 'USER_QRIS', 'QRIS_PRODUKTIF'],
            \App\Models\RkaQris::query()->pluck('kpi')->all(),
        );
    }

    public function test_non_admin_ditolak(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/edc')->assertForbidden();
        $this->actingAs($ro)->get('/admin/upload/qris')->assertForbidden();
        $this->actingAs($ro)->get('/admin/rka/edc')->assertForbidden();
        $this->actingAs($ro)
            ->post('/admin/upload/edc', ['berkas' => $this->berkas(self::HEADER)])
            ->assertForbidden();
    }
}
