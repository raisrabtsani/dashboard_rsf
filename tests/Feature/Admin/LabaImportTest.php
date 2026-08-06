<?php

namespace Tests\Feature\Admin;

use App\Models\Laba;
use App\Models\RkaLaba;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LabaImportTest extends TestCase
{
    use RefreshDatabase;

    // Cabang 12 = KC Bogor; uker 804 = UNIT PURBASARI BOGOR (uko "Micro").
    private const CABANG = 12;

    private const UKER = 804;

    // uker 595 = KCP IPB, dilaporkan bisnis sebagai uko "Sub-Branch Office".
    private const UKER_SBO = 595;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function berkas(string $isi, string $namaAsli = 'laba.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaAsli, 'text/csv', null, true);
    }

    private function unggah(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/upload/laba', ['berkas' => $this->berkas($isi)]);
    }

    private function unggahRka(string $isi): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/rka/laba', ['berkas' => $this->berkas($isi, 'rka-laba.csv')]);
    }

    // Format baru: kolom `uko` (jenis kantor) menggantikan `segmen`.
    private const HEADER = "id_cabang,id_uker,uko,tahun,bulan,laba\n";

    public function test_upload_menyimpan_laba_kumulatif(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026,1,100000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026,2,250000000\n";

        $this->unggah($isi)->assertOk();

        $this->assertSame(2, Laba::query()->count());
        $this->assertSame(250_000_000.0, (float) Laba::query()->where('bulan', 2)->value('laba'));
    }

    public function test_format_asli_klien_dengan_kolom_unit_kerja_dan_angka_koma(): void
    {
        // Bentuk berkas asli: header berkapital/berspasi, kolom tambahan
        // "Unit Kerja" (diabaikan), dan angka berformat Excel (koma + spasi).
        $isi = "id_cabang,id_uker,uko,Unit Kerja,Tahun,Bulan, Laba \n"
            .'12,12,Branch Office,KC Bogor,2026,Januari," 4,852,506,150 "'."\n"
            .'12,595,Sub-Branch Office,KCP IPB,2026,Januari," 778,485,282 "'."\n"
            .'12,804,Micro,UNIT PURBASARI BOGOR,2026,Januari," 197,591,346 "'."\n";

        $this->unggah($isi)->assertOk();

        $this->assertSame(3, Laba::query()->count());
        $this->assertSame(
            4_852_506_150.0,
            (float) Laba::query()->where('uker_id', 12)->value('laba'),
        );
        // Segmen (uko) tersimpan apa adanya dari berkas.
        $this->assertEqualsCanonicalizing(
            ['Branch Office', 'Sub-Branch Office', 'Micro'],
            Laba::query()->pluck('segmen')->all(),
        );
    }

    public function test_uko_disimpan_ke_kolom_segmen(): void
    {
        // Label uko dari berkas diambil apa adanya (bukan diturunkan dari master).
        // uker 595 tipe UNIT di master, tapi dilaporkan "Sub-Branch Office".
        $isi = self::HEADER.self::CABANG.','.self::UKER_SBO.",Sub-Branch Office,2026,1,100000000\n";

        $this->unggah($isi)->assertOk();

        $this->assertSame('Sub-Branch Office', Laba::query()->value('segmen'));
    }

    public function test_upload_ulang_idempoten_lewat_upsert(): void
    {
        $isi = self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,1,100000000\n";

        $this->unggah($isi)->assertOk();
        $this->unggah($isi)->assertOk();

        $this->assertSame(1, Laba::query()->count());
        $this->assertSame(100_000_000.0, (float) Laba::query()->value('laba'));
    }

    public function test_upload_menimpa_bukan_menggandakan(): void
    {
        $buat = fn (int $laba) => self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,1,{$laba}\n";

        $this->unggah($buat(100_000_000))->assertOk();
        $this->unggah($buat(300_000_000))->assertOk();

        $this->assertSame(1, Laba::query()->count());
        $this->assertSame(300_000_000.0, (float) Laba::query()->value('laba'));
    }

    public function test_laba_negatif_diterima(): void
    {
        // Rugi = laba negatif. Harus tersimpan apa adanya.
        $isi = self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,1,-50000000\n";

        $this->unggah($isi)->assertOk();
        $this->assertSame(-50_000_000.0, (float) Laba::query()->value('laba'));
    }

    public function test_sel_laba_kosong_dilewati(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026,1,\n"
            .self::CABANG.','.self::UKER_SBO.",Sub-Branch Office,2026,1,100000000\n";

        $respons = $this->unggah($isi)->assertOk();

        $this->assertSame(1, Laba::query()->count());
        $this->assertSame(1, $respons->json('hasil.dilewati'));
    }

    public function test_baris_kembar_ditolak(): void
    {
        $isi = self::HEADER
            .self::CABANG.','.self::UKER.",Micro,2026,1,100000000\n"
            .self::CABANG.','.self::UKER.",Micro,2026,1,200000000\n";

        $respons = $this->unggah($isi);

        $respons->assertStatus(422);
        $this->assertStringContainsString('kembar', $respons->json('message'));
        $this->assertSame(0, Laba::query()->count());
    }

    public function test_uker_tak_dikenal_dilewati_dan_dilaporkan(): void
    {
        // Importer bersifat toleran per-baris (MelaporkanImport): baris dengan
        // uker tak dikenal TIDAK menggagalkan seluruh berkas — dilewati (tidak
        // tersimpan) lalu dilaporkan sebagai baris tidak valid.
        $isi = self::HEADER.self::CABANG.",999999,Micro,2026,1,100000000\n";

        $respons = $this->unggah($isi)->assertOk();

        $this->assertSame(0, Laba::query()->count());
        $this->assertSame(1, $respons->json('hasil.laporan.tidak_valid'));
    }

    public function test_riwayat_unduh_dan_hapus_per_periode(): void
    {
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,1,100000000\n")->assertOk();
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,2,250000000\n")->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/laba/riwayat')
            ->assertOk()
            ->json('riwayat');

        // Terurut terbaru dulu: Feb lalu Jan.
        $this->assertSame('2026-02', $riwayat[0]['periode']);
        $this->assertSame(250.0, (float) $riwayat[0]['total']);

        $isi = $this->actingAs($this->admin())
            ->get('/admin/upload/laba/unduh/2026/1')
            ->assertOk()
            ->streamedContent();
        // Header unduhan memakai kolom `uko`, nilainya kolom segmen.
        $this->assertStringContainsString('uko', $isi);
        $this->assertStringContainsString('12,804,Micro,2026,1', $isi);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/laba/2026/1')
            ->assertOk();

        $this->assertSame(1, Laba::query()->count());
        $this->assertSame(2, (int) Laba::query()->value('bulan'));
    }

    public function test_hapus_per_tahun(): void
    {
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2025,12,100000000\n")->assertOk();
        $this->unggah(self::HEADER.self::CABANG.','.self::UKER.",Micro,2026,1,100000000\n")->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/laba/year/2025')
            ->assertOk();

        $this->assertSame(1, Laba::query()->count());
        $this->assertSame(2026, (int) Laba::query()->value('tahun'));
    }

    // --- RKA ---------------------------------------------------------------

    public function test_rka_menerima_nama_bulan_dan_upsert(): void
    {
        // Header "Uko" berkapital menguji toleransi nama kolom.
        $isi = "id_cabang,id_uker,Uko,Tahun,Bulan, RKA \n"
            .self::CABANG.','.self::UKER.',Micro,2026,Februari," 500,000,000 "'."\n";

        $this->unggahRka($isi)->assertOk();

        $baris = RkaLaba::query()->sole();
        $this->assertSame(2, $baris->bulan);
        $this->assertSame(500_000_000.0, (float) $baris->target);
    }

    public function test_rka_alias_segmen_masih_diterima(): void
    {
        // Berkas lama berkolom `segmen` tetap diterima lewat alias uko.
        $isi = "id_cabang,id_uker,segmen,tahun,bulan,target\n"
            .self::CABANG.','.self::UKER.",Micro,2026,1,\n"
            .self::CABANG.','.self::UKER_SBO.",Sub-Branch Office,2026,1,100000000\n";

        $respons = $this->unggahRka($isi)->assertOk();

        $this->assertSame(1, RkaLaba::query()->count());
        $this->assertSame(1, $respons->json('hasil.dilewati'));
    }

    public function test_hapus_rka_per_tahun(): void
    {
        RkaLaba::factory()->create(['tahun' => 2025, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG]);
        RkaLaba::factory()->create(['tahun' => 2026, 'uker_id' => self::UKER, 'cabang_id' => self::CABANG, 'bulan' => 3]);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/rka/laba/year/2025')
            ->assertOk();

        $this->assertSame(1, RkaLaba::query()->count());
    }

    public function test_non_admin_ditolak(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/laba')->assertForbidden();
        $this->actingAs($ro)->get('/admin/rka/laba')->assertForbidden();
        $this->actingAs($ro)
            ->post('/admin/upload/laba', ['berkas' => $this->berkas(self::HEADER)])
            ->assertForbidden();
    }
}
