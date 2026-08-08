<?php

namespace Tests\Feature\Admin;

use App\Models\Ph;
use App\Models\User;
use App\Services\PhCsvImportService;
use App\Support\Segmen;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhImportTest extends TestCase
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
    private function berkas(string $isi, string $nama = 'ph.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $nama, 'text/csv', null, true);
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     */
    private function csv(array $baris): UploadedFile
    {
        $isi = implode(',', PhCsvImportService::KOLOM)."\n";

        foreach ($baris as $b) {
            $isi .= implode(',', $b)."\n";
        }

        return $this->berkas($isi);
    }

    private function unggah(UploadedFile $berkas): TestResponse
    {
        return $this->actingAs($this->admin())->post('/admin/upload/ph', ['berkas' => $berkas]);
    }

    // --- Agregasi ---------------------------------------------------------

    public function test_baris_debitur_dijumlahkan_per_kombinasi(): void
    {
        $respons = $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 2_500_000],
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 500_000],
        ]))->assertOk();

        $this->assertSame(1, Ph::query()->count());
        $this->assertSame(3, $respons->json('hasil.sumber'));
        $this->assertSame(4_000_000.0, (float) Ph::query()->value('saldo'));
    }

    public function test_periode_dinormalkan_ke_akhir_bulan(): void
    {
        // Berkas menulis tanggal 1; maknanya tetap "bulan Maret".
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-01', 1_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-03-20', 2_000_000],
        ]))->assertOk();

        // Dua-duanya jatuh ke periode yang sama, jadi dijumlahkan.
        $this->assertSame(1, Ph::query()->count());
        $this->assertSame('2026-03-31', Ph::query()->value('periode'));
        $this->assertSame(3_000_000.0, (float) Ph::query()->value('saldo'));
    }

    public function test_segmen_dinormalkan_ke_taksonomi_kanonik(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Small', '2026-03-31', 1_000_000],
            [self::CABANG, self::UKER, 'Medium', '2026-03-31', 2_000_000],
        ]))->assertOk();

        // Small + Medium sama-sama SME -> satu baris.
        $this->assertSame(1, Ph::query()->count());
        $this->assertSame(Segmen::SME, Ph::query()->value('segmen'));
        $this->assertSame(3_000_000.0, (float) Ph::query()->value('saldo'));
    }


    public function test_sumif_diaudit_berdasarkan_id_uker_dan_periode(): void
    {
        $respons = $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 2_000_000],
            [self::CABANG, self::UKER, 'Consumer', '2026-03-31', 3_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-04-30', 4_000_000],
        ]))->assertOk();

        // Dua kombinasi SUMIF: UKER+Maret dan UKER+April.
        $this->assertSame(2, $respons->json('hasil.sumif.kombinasi'));
        $this->assertSame(2, $respons->json('hasil.sumif.baris_tergabung'));
        $this->assertSame(10_000_000.0, (float) $respons->json('hasil.sumif.total_sumber'));
        $this->assertSame(10_000_000.0, (float) $respons->json('hasil.sumif.total_hasil'));

        // Rincian segmen tetap disimpan agar filter segmen tidak rusak.
        $this->assertSame(3, Ph::query()->count());
        $this->assertSame(
            6_000_000.0,
            (float) Ph::query()->where('periode', '2026-03-31')->sum('saldo'),
        );
    }

    // --- Fallback uker ----------------------------------------------------

    public function test_uker_kosong_jatuh_ke_level_cabang(): void
    {
        $respons = $this->unggah($this->csv([
            [self::CABANG, '', 'Micro', '2026-03-31', 5_000_000],
        ]))->assertOk();

        // Nilainya TIDAK boleh hilang — dicatat di baris uker level BO.
        $this->assertSame(1, Ph::query()->count());
        $this->assertSame(self::CABANG, Ph::query()->value('uker_id'));
        $this->assertSame(5_000_000.0, (float) Ph::query()->value('saldo'));
        $this->assertSame(1, $respons->json('hasil.fallback'));
    }

    public function test_uker_tak_dikenal_juga_jatuh_ke_level_cabang(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, 999999, 'Micro', '2026-03-31', 7_000_000],
        ]))->assertOk();

        $this->assertSame(self::CABANG, Ph::query()->value('uker_id'));
        $this->assertSame(7_000_000.0, (float) Ph::query()->value('saldo'));
    }

    public function test_id_uker_menjadi_sumber_kebenaran_cabang_dan_tetap_disumif(): void
    {
        // Uker 5438 milik cabang 159. Dua baris menulis id_cabang berbeda,
        // tetapi karena id_uker sama dan periodenya sama, saldo wajib tersumif.
        $respons = $this->unggah($this->csv([
            [621, self::UKER, 'Micro', '2026-03-31', 3_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 2_000_000],
        ]))->assertOk();

        $this->assertSame(1, Ph::query()->count());
        $this->assertSame(self::CABANG, Ph::query()->value('cabang_id'));
        $this->assertSame(self::UKER, Ph::query()->value('uker_id'));
        $this->assertSame(5_000_000.0, (float) Ph::query()->value('saldo'));
        $this->assertSame(1, $respons->json('hasil.koreksi_cabang'));
        $this->assertSame(1, $respons->json('hasil.sumif.kombinasi'));
    }

    public function test_cabang_tak_dikenal_dikoreksi_bila_id_uker_valid(): void
    {
        $respons = $this->unggah($this->csv([
            [999999, self::UKER, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertOk();

        $this->assertSame(self::CABANG, Ph::query()->value('cabang_id'));
        $this->assertSame(self::UKER, Ph::query()->value('uker_id'));
        $this->assertSame(1, $respons->json('hasil.koreksi_cabang'));
    }

    public function test_id_uker_dan_id_cabang_sama_sama_tidak_valid_ditolak(): void
    {
        $this->unggah($this->csv([
            [999999, 888888, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertStatus(422);

        $this->assertSame(0, Ph::query()->count());
    }

    // --- Skip vs timpa ----------------------------------------------------

    public function test_upload_admin_melewati_periode_yang_sudah_ada(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertOk();

        // Berkas kumulatif: Maret (sudah ada) + April (baru).
        $respons = $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 9_999_999],
            [self::CABANG, self::UKER, 'Micro', '2026-04-30', 2_000_000],
        ]))->assertOk();

        // April masuk, Maret DILEWATI (nilainya tidak berubah).
        $this->assertSame(['2026-03-31'], $respons->json('hasil.dilewati'));
        $this->assertSame(2, Ph::query()->count());
        $this->assertSame(
            1_000_000.0,
            (float) Ph::query()->where('periode', '2026-03-31')->value('saldo'),
        );
        $this->assertSame(
            2_000_000.0,
            (float) Ph::query()->where('periode', '2026-04-30')->value('saldo'),
        );
    }

    public function test_upload_ditolak_bila_seluruh_periodenya_sudah_ada(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertOk();

        $respons = $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 5_000_000],
        ]));

        // 409: berkasnya benar, tapi tidak ada yang bisa dikerjakan.
        $respons->assertStatus(409);
        $this->assertSame(1_000_000.0, (float) Ph::query()->value('saldo'));
    }

    public function test_cli_import_ph_menimpa_periode_yang_sudah_ada(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'php').'.csv';
        file_put_contents(
            $path,
            implode(',', PhCsvImportService::KOLOM)."\n".
            self::CABANG.','.self::UKER.",Micro,2026-03-31,8800000\n",
        );

        $this->artisan('import:ph', ['berkas' => $path])->assertSuccessful();

        // Berbeda dari upload admin: nilainya DITIMPA.
        $this->assertSame(1, Ph::query()->count());
        $this->assertSame(8_800_000.0, (float) Ph::query()->value('saldo'));
    }

    public function test_cli_bisa_diminta_melewati_seperti_upload_admin(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
        ]))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'php').'.csv';
        file_put_contents(
            $path,
            implode(',', PhCsvImportService::KOLOM)."\n".
            self::CABANG.','.self::UKER.",Micro,2026-03-31,8800000\n",
        );

        $this->artisan('import:ph', ['berkas' => $path, '--lewati' => true])->assertFailed();

        $this->assertSame(1_000_000.0, (float) Ph::query()->value('saldo'));
    }

    // --- Riwayat / hapus / akses -----------------------------------------

    public function test_riwayat_dan_hapus_per_periode(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2026-03-31', 1_000_000],
            [self::CABANG, self::UKER, 'SME', '2026-04-30', 2_000_000],
        ]))->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/ph/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertCount(2, $riwayat);
        $this->assertSame('2026-04-30', $riwayat[0]['periode']);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/ph/2026-04-30')
            ->assertOk();

        $this->assertSame(1, Ph::query()->count());
    }

    public function test_hapus_per_tahun(): void
    {
        $this->unggah($this->csv([
            [self::CABANG, self::UKER, 'Micro', '2025-12-31', 1_000_000],
            [self::CABANG, self::UKER, 'Micro', '2026-01-31', 2_000_000],
        ]))->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/ph/year/2025')
            ->assertOk();

        $this->assertSame(1, Ph::query()->count());
        $this->assertSame('2026-01-31', Ph::query()->value('periode'));
    }

    public function test_non_admin_ditolak(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/ph')->assertForbidden();
        $this->actingAs($ro)->getJson('/admin/upload/ph/riwayat')->assertForbidden();
        $this->actingAs($ro)->deleteJson('/admin/upload/ph/2026-03-31')->assertForbidden();
    }
}
