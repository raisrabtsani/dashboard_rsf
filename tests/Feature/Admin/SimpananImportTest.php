<?php

namespace Tests\Feature\Admin;

use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\User;
use App\Services\SimpananCsvImportService;
use App\Support\Spreadsheet;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet as PhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SimpananImportTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    /** Uker milik cabang 159. */
    private const UKER_A = 5438;

    private const UKER_B = 5439;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Berkas contoh: 2 uker x 3 produk = 6 baris, total saldo 210.000.000.000.
     *
     * @return list<array<int, string|int>>
     */
    private function barisContoh(string $tanggal = '2026-08-05'): array
    {
        return [
            [self::CABANG, self::UKER_A, 'Tabungan', 'Ritel', $tanggal, 50_000_000_000],
            [self::CABANG, self::UKER_A, 'Giro', 'Ritel', $tanggal, 20_000_000_000],
            [self::CABANG, self::UKER_A, 'Deposito', 'Ritel', $tanggal, 30_000_000_000],
            [self::CABANG, self::UKER_B, 'Tabungan', 'Mikro', $tanggal, 60_000_000_000],
            [self::CABANG, self::UKER_B, 'Giro', 'Mikro', $tanggal, 15_000_000_000],
            [self::CABANG, self::UKER_B, 'Deposito', 'Mikro', $tanggal, 35_000_000_000],
        ];
    }

    private const TOTAL_CONTOH = 210_000_000_000;

    /**
     * @param  list<array<int, mixed>>|null  $baris
     */
    /**
     * Berkas sementara TANPA ekstensi, meniru unggahan HTTP sungguhan.
     *
     * PHP menyimpan berkas unggahan sebagai "phpA1B2.tmp", jadi importer tidak
     * boleh menebak format dari path — hanya dari nama asli kiriman klien.
     * Helper test yang memakai path ber-.csv dulu menyembunyikan bug ini.
     */
    private function berkasUnggahan(string $isi, string $namaAsli, string $mime = 'text/csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaAsli, $mime, null, true);
    }

    /**
     * @param  list<array<int, mixed>>|null  $baris
     */
    private function berkasCsv(?array $baris = null, string $nama = 'simpanan.csv'): UploadedFile
    {
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, SimpananCsvImportService::KOLOM, escape: '');

        foreach ($baris ?? $this->barisContoh() as $b) {
            fputcsv($buffer, $b, escape: '');
        }

        rewind($buffer);
        $isi = stream_get_contents($buffer);
        fclose($buffer);

        return $this->berkasUnggahan($isi, $nama);
    }

    private function berkasExcel(): UploadedFile
    {
        $buku = new PhpSpreadsheet;
        $lembar = $buku->getActiveSheet();
        $lembar->fromArray(SimpananCsvImportService::KOLOM, null, 'A1');
        $lembar->fromArray($this->barisContoh(), null, 'A2');

        // Writer butuh nama ber-.xlsx; isinya lalu dipindah ke berkas tanpa
        // ekstensi supaya jalur unggahannya sama dengan produksi.
        $xlsx = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($buku))->save($xlsx);

        return $this->berkasUnggahan(
            file_get_contents($xlsx),
            'simpanan.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    private function unggah(UploadedFile $berkas): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())
            ->post('/admin/upload/simpanan', ['berkas' => $berkas]);
    }

    // --- Import ----------------------------------------------------------

    public function test_upload_csv_menghasilkan_jumlah_baris_dan_total_saldo_yang_sesuai(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $this->assertSame(6, Simpanan::query()->count());
        $this->assertSame(
            (float) self::TOTAL_CONTOH,
            (float) Simpanan::query()->sum('saldo'),
        );
    }

    public function test_upload_excel_menghasilkan_hasil_yang_sama_dengan_csv(): void
    {
        $this->unggah($this->berkasExcel())->assertOk();

        $this->assertSame(6, Simpanan::query()->count());
        $this->assertSame(
            (float) self::TOTAL_CONTOH,
            (float) Simpanan::query()->sum('saldo'),
        );
    }

    public function test_nilai_disimpan_rupiah_penuh_apa_adanya(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $baris = Simpanan::query()
            ->where('uker_id', self::UKER_A)
            ->where('produk', 'Tabungan')
            ->sole();

        $this->assertSame(50_000_000_000.0, (float) $baris->saldo);
        $this->assertSame('Ritel', $baris->segmentasi);
        $this->assertSame('2026-08-05', $baris->tanggal);
    }

    public function test_upload_ditolak_bila_tanggal_sudah_ada(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $respons = $this->unggah($this->berkasCsv());

        $respons->assertStatus(409);
        $this->assertStringContainsString('2026-08-05', $respons->json('message'));
        // Data lama tidak boleh berubah/berganda.
        $this->assertSame(6, Simpanan::query()->count());
    }

    public function test_upload_ditolak_bila_uker_tidak_ada_di_master(): void
    {
        $berkas = $this->berkasCsv([
            [self::CABANG, 999999, 'Tabungan', 'Ritel', '2026-08-05', 1_000_000],
        ]);

        $this->unggah($berkas)->assertStatus(422);
        $this->assertSame(0, Simpanan::query()->count());
    }

    public function test_upload_ditolak_bila_uker_bukan_milik_cabang_itu(): void
    {
        // Uker 5438 milik cabang 159, bukan 621.
        $berkas = $this->berkasCsv([
            [621, self::UKER_A, 'Tabungan', 'Ritel', '2026-08-05', 1_000_000],
        ]);

        $this->unggah($berkas)->assertStatus(422);
        $this->assertSame(0, Simpanan::query()->count());
    }

    public function test_upload_ditolak_bila_produk_tidak_dikenal(): void
    {
        $berkas = $this->berkasCsv([
            [self::CABANG, self::UKER_A, 'Tabungn', 'Ritel', '2026-08-05', 1_000_000],
        ]);

        $this->unggah($berkas)->assertStatus(422);
        $this->assertSame(0, Simpanan::query()->count());
    }

    public function test_upload_gagal_tidak_menyisakan_data_separuh(): void
    {
        // Baris terakhir cacat; tidak boleh ada baris yang lolos duluan.
        $berkas = $this->berkasCsv([
            [self::CABANG, self::UKER_A, 'Tabungan', 'Ritel', '2026-08-05', 1_000_000],
            [self::CABANG, self::UKER_A, 'Giro', 'Ritel', '2026-08-05', 'bukan-angka'],
        ]);

        $this->unggah($berkas)->assertStatus(422);
        $this->assertSame(0, Simpanan::query()->count());
    }

    public function test_kolom_wajib_yang_hilang_ditolak_dengan_pesan_jelas(): void
    {
        $respons = $this->unggah($this->berkasUnggahan(
            "id_cabang,id_uker,produk\n159,5438,Tabungan\n",
            'salah.csv',
        ));

        // 422 = berkasnya salah (bukan 409 yang berarti bentrok data).
        $respons->assertStatus(422);
        $this->assertStringContainsString('segmentasi', $respons->json('message'));
    }

    public function test_baris_berulang_dijumlahkan_bukan_menabrak_kunci_unik(): void
    {
        // Berkas sumber berisi satu baris per REKENING, jadi satu kombinasi
        // (uker, produk, segmentasi, tanggal) bisa muncul puluhan kali.
        $berkas = $this->berkasCsv([
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 30_000_000],
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 3_340_000_000],
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 250_000_000],
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 100_000_000],
        ]);

        $respons = $this->unggah($berkas)->assertOk();

        // 4 baris berkas -> 1 baris posisi.
        $this->assertSame(1, Simpanan::query()->count());
        $this->assertSame(4, $respons->json('hasil.sumber'));
        $this->assertSame(1, $respons->json('hasil.baris'));

        // Dijumlahkan, BUKAN last-wins dan bukan MAX.
        $this->assertSame(3_720_000_000.0, (float) Simpanan::query()->value('saldo'));
    }

    public function test_penjumlahan_tidak_mencampur_kombinasi_yang_berbeda(): void
    {
        $berkas = $this->berkasCsv([
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 1_000_000],
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-01', 2_000_000],
            // beda segmentasi
            [self::CABANG, self::UKER_A, 'Deposito', 'Ritel', '2026-08-01', 5_000_000],
            // beda produk
            [self::CABANG, self::UKER_A, 'Tabungan', 'Micro', '2026-08-01', 7_000_000],
            // beda tanggal
            [self::CABANG, self::UKER_A, 'Deposito', 'Micro', '2026-08-02', 9_000_000],
        ]);

        $this->unggah($berkas)->assertOk();

        $this->assertSame(4, Simpanan::query()->count());
        $this->assertSame(3_000_000.0, (float) Simpanan::query()
            ->where(['produk' => 'Deposito', 'segmentasi' => 'Micro', 'tanggal' => '2026-08-01'])
            ->value('saldo'));
        $this->assertSame(24_000_000.0, (float) Simpanan::query()->sum('saldo'));
    }

    public function test_menerima_header_gaya_ekspor_tableau(): void
    {
        // Alat pelaporan menamai kolom tanggal "<bagian tanggal> of <Nama Field>";
        // nilai memakai koma ribuan dan tanggal M/D/Y.
        $isi = "id_cabang,id_uker,produk,segmentasi, Saldo ,\"Month, Day, Year of Posisi\"\n"
            .self::CABANG.','.self::UKER_A.',Deposito,Micro," 30,000,000 ",8/1/2026'."\n"
            .self::CABANG.','.self::UKER_A.',Tabungan,Ritel," 1,250,500 ",8/3/2026'."\n";

        $this->unggah($this->berkasUnggahan($isi, '08. Full Simpanan Agustus 2026.csv'))->assertOk();

        $this->assertSame(2, Simpanan::query()->count());

        $deposito = Simpanan::query()->where('produk', 'Deposito')->sole();
        // "8/1/2026" = Month/Day/Year -> 1 Agustus, BUKAN 8 Januari.
        $this->assertSame('2026-08-01', $deposito->tanggal);
        $this->assertSame(30_000_000.0, (float) $deposito->saldo);

        $this->assertSame('2026-08-03', Simpanan::query()->where('produk', 'Tabungan')->value('tanggal'));
    }

    public function test_format_ditentukan_dari_nama_asli_bukan_dari_berkas_sementara(): void
    {
        // REGRESI: PHP menyimpan unggahan sebagai "phpXXXX.tmp". Importer yang
        // menebak format dari path akan membaca ekstensi ".tmp" lalu menolak
        // berkas yang sah — gagal hanya di produksi, tidak pernah di test yang
        // memakai berkas sementara ber-.csv.
        $berkas = $this->berkasCsv();

        $this->assertNotContains(
            pathinfo($berkas->getRealPath(), PATHINFO_EXTENSION),
            Spreadsheet::EKSTENSI,
            'Path sementara tidak boleh berekstensi yang didukung, agar importer '.
            'benar-benar dipaksa memakai nama asli kiriman klien.',
        );

        $this->unggah($berkas)->assertOk();
        $this->assertSame(6, Simpanan::query()->count());
    }

    public function test_excel_juga_dikenali_dari_nama_asli(): void
    {
        $berkas = $this->berkasExcel();

        $this->assertNotContains(
            pathinfo($berkas->getRealPath(), PATHINFO_EXTENSION),
            Spreadsheet::EKSTENSI,
        );

        $this->unggah($berkas)->assertOk();
        $this->assertSame(6, Simpanan::query()->count());
    }

    public function test_pesan_error_menyebut_nama_berkas_yang_dikenal_user(): void
    {
        $respons = $this->unggah($this->berkasUnggahan(
            "id_cabang,id_uker,produk\n159,5438,Tabungan\n",
            'Target Simpanan Agustus.csv',
        ));

        $respons->assertStatus(422);
        // Bukan "phpA1B2.tmp" yang tidak berarti apa-apa bagi admin.
        $this->assertStringContainsString('Target Simpanan Agustus.csv', $respons->json('message'));
    }

    public function test_berkas_dengan_ekstensi_tak_didukung_ditolak_validasi(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
        file_put_contents($path, '%PDF-1.4');

        $this->actingAs($this->admin())
            ->post('/admin/upload/simpanan', [
                'berkas' => new UploadedFile($path, 'x.pdf', 'application/pdf', null, true),
            ])
            ->assertSessionHasErrors('berkas');
    }

    // --- Riwayat / unduh / hapus -----------------------------------------

    public function test_riwayat_menampilkan_ringkasan_per_tanggal(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $riwayat = $this->actingAs($this->admin())
            ->getJson('/admin/upload/simpanan/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertCount(1, $riwayat);
        $this->assertSame('2026-08-05', $riwayat[0]['tanggal']);
        $this->assertSame(6, $riwayat[0]['jumlah_baris']);
        // Riwayat memakai satuan juta, sama seperti dashboard.
        $this->assertSame(210_000.0, (float) $riwayat[0]['total_saldo']);
    }

    public function test_unduh_ulang_mengembalikan_csv_dengan_kolom_yang_sama(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $respons = $this->actingAs($this->admin())
            ->get('/admin/upload/simpanan/unduh/2026-08-05')
            ->assertOk();

        $isi = $respons->streamedContent();

        $this->assertStringContainsString(implode(',', SimpananCsvImportService::KOLOM), $isi);
        $this->assertStringContainsString('159,5438,Tabungan,Ritel,2026-08-05', $isi);
        // 1 header + 6 baris data.
        $this->assertSame(7, count(array_filter(explode("\n", trim($isi)))));
    }

    public function test_unduh_tanggal_kosong_membalas_404(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/upload/simpanan/unduh/2020-01-01')
            ->assertNotFound();
    }

    public function test_hapus_per_tanggal(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/simpanan/2026-08-05')
            ->assertOk();

        $this->assertSame(0, Simpanan::query()->count());
    }

    public function test_hapus_per_bulan_hanya_menghapus_bulan_itu(): void
    {
        $this->unggah($this->berkasCsv($this->barisContoh('2026-08-05')))->assertOk();
        $this->unggah($this->berkasCsv($this->barisContoh('2026-08-20')))->assertOk();
        $this->unggah($this->berkasCsv($this->barisContoh('2026-09-01')))->assertOk();

        $this->assertSame(18, Simpanan::query()->count());

        $this->actingAs($this->admin())
            ->deleteJson('/admin/upload/simpanan/bulk-month', ['tahun' => 2026, 'bulan' => 8])
            ->assertOk();

        // September tersisa.
        $this->assertSame(6, Simpanan::query()->count());
        $this->assertSame('2026-09-01', Simpanan::query()->value('tanggal'));
    }

    public function test_upload_bisa_diulang_setelah_tanggalnya_dihapus(): void
    {
        $this->unggah($this->berkasCsv())->assertOk();
        $this->actingAs($this->admin())->deleteJson('/admin/upload/simpanan/2026-08-05')->assertOk();

        $this->unggah($this->berkasCsv())->assertOk();

        $this->assertSame(6, Simpanan::query()->count());
    }

    // --- RKA -------------------------------------------------------------

    public function test_upload_rka_menyimpan_target_per_bulan(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rka').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['id_cabang', 'id_uker', 'produk', 'tahun', 'bulan', 'target'], escape: '');

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            fputcsv($handle, [self::CABANG, self::UKER_A, 'Tabungan', 2026, $bulan, 10_000_000_000], escape: '');
        }

        fclose($handle);

        $this->actingAs($this->admin())
            ->post('/admin/rka/simpanan', ['berkas' => new UploadedFile($path, 'rka.csv', 'text/csv', null, true)])
            ->assertOk();

        $this->assertSame(12, RkaSimpanan::query()->count());
        $this->assertSame(120_000_000_000.0, (float) RkaSimpanan::query()->sum('target'));
    }

    public function test_upload_rka_menimpa_target_lama_bukan_menggandakan(): void
    {
        $buat = function (int $target) {
            $path = tempnam(sys_get_temp_dir(), 'rka').'.csv';
            $handle = fopen($path, 'w');
            fputcsv($handle, ['id_cabang', 'id_uker', 'produk', 'tahun', 'bulan', 'target'], escape: '');
            fputcsv($handle, [self::CABANG, self::UKER_A, 'Tabungan', 2026, 1, $target], escape: '');
            fclose($handle);

            return new UploadedFile($path, 'rka.csv', 'text/csv', null, true);
        };

        $this->actingAs($this->admin())->post('/admin/rka/simpanan', ['berkas' => $buat(1_000_000)])->assertOk();
        $this->actingAs($this->admin())->post('/admin/rka/simpanan', ['berkas' => $buat(2_000_000)])->assertOk();

        // RKA boleh direvisi sepanjang tahun berjalan — upsert, bukan tolak.
        $this->assertSame(1, RkaSimpanan::query()->count());
        $this->assertSame(2_000_000.0, (float) RkaSimpanan::query()->value('target'));
    }

    /**
     * Menulis CSV mentah apa adanya (termasuk header aneh & nilai berkutip).
     */
    private function berkasMentah(string $isi, string $nama = 'rka.csv'): UploadedFile
    {
        return $this->berkasUnggahan($isi, $nama);
    }

    private function unggahRka(UploadedFile $berkas): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->post('/admin/rka/simpanan', ['berkas' => $berkas]);
    }

    /**
     * Bentuk persis berkas "Full Target Simpanan 2026.csv" dari unit bisnis:
     * header kapital & berspasi, bulan sebagai nama, nilai berkoma & berkutip.
     */
    public function test_rka_menerima_format_berkas_unit_bisnis(): void
    {
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk, RKA \n"
            .'159,5438,2026,Februari,Ritel,Giro," 10,093,170,076 "'."\n"
            .'159,5438,2026,Januari,Ritel,Tabungan," 458,798,688,658 "'."\n";

        $this->unggahRka($this->berkasMentah($isi))->assertOk();

        $this->assertSame(2, RkaSimpanan::query()->count());

        $giro = RkaSimpanan::query()->where('produk', 'Giro')->sole();
        $this->assertSame(2, $giro->bulan);                     // "Februari" -> 2
        $this->assertSame('Ritel', $giro->segmentasi);
        $this->assertSame(10_093_170_076.0, (float) $giro->target);

        $tabungan = RkaSimpanan::query()->where('produk', 'Tabungan')->sole();
        $this->assertSame(1, $tabungan->bulan);                 // "Januari" -> 1
        $this->assertSame(458_798_688_658.0, (float) $tabungan->target);
    }

    public function test_rka_melewati_baris_bertarget_kosong_dan_melaporkannya(): void
    {
        // Unit mikro tidak menjual Giro -> sel target kosong = tidak punya target.
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk, RKA \n"
            .'159,5438,2026,April,Micro,Giro,'."\n"
            .'159,5438,2026,April,Micro,Tabungan," 34,836,979,239 "'."\n";

        $respons = $this->unggahRka($this->berkasMentah($isi))->assertOk();

        // Hanya baris bertarget yang masuk; yang kosong TIDAK disimpan sebagai 0.
        $this->assertSame(1, RkaSimpanan::query()->count());
        $this->assertSame('Tabungan', RkaSimpanan::query()->value('produk'));
        $this->assertSame(1, $respons->json('hasil.dilewati'));
        $this->assertStringContainsString('dilewati', $respons->json('message'));
    }

    public function test_rka_membedakan_segmentasi_pada_kunci_unik(): void
    {
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk,RKA\n"
            ."159,5438,2026,Januari,Ritel,Tabungan,1000000\n"
            ."159,5438,2026,Januari,Micro,Tabungan,2000000\n";

        $this->unggahRka($this->berkasMentah($isi))->assertOk();

        // Dua segmentasi berbeda TIDAK boleh saling menimpa.
        $this->assertSame(2, RkaSimpanan::query()->count());
        $this->assertSame(3_000_000.0, (float) RkaSimpanan::query()->sum('target'));
    }

    public function test_rka_menolak_baris_kembar_pada_kunci_yang_sama(): void
    {
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk,RKA\n"
            ."159,5438,2026,Januari,Ritel,Tabungan,1000000\n"
            ."159,5438,2026,Januari,Ritel,Tabungan,2000000\n";

        $respons = $this->unggahRka($this->berkasMentah($isi));

        // upsert hanya menyimpan salah satu — lebih baik ditolak daripada
        // sebagian target hilang diam-diam.
        $respons->assertStatus(422);
        $this->assertStringContainsString('kembar', $respons->json('message'));
        $this->assertSame(0, RkaSimpanan::query()->count());
    }

    public function test_rka_menolak_nama_bulan_yang_tidak_dikenal(): void
    {
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk,RKA\n"
            ."159,5438,2026,Janwari,Ritel,Tabungan,1000000\n";

        $respons = $this->unggahRka($this->berkasMentah($isi));

        $respons->assertStatus(422);
        $this->assertStringContainsString('Janwari', $respons->json('message'));
    }

    public function test_rka_mengambil_cabang_dari_master_bukan_dari_berkas(): void
    {
        // id_cabang di berkas salah (621), master bilang uker 5438 milik 159.
        $isi = "id_cabang,id_uker,Tahun,Bulan,Segmentasi,Produk,RKA\n"
            ."621,5438,2026,Januari,Ritel,Tabungan,1000000\n";

        $this->unggahRka($this->berkasMentah($isi))->assertOk();

        $this->assertSame(self::CABANG, RkaSimpanan::query()->value('cabang_id'));
    }

    public function test_hapus_rka_per_tahun(): void
    {
        RkaSimpanan::factory()->create(['tahun' => 2025, 'uker_id' => self::UKER_A, 'cabang_id' => self::CABANG]);
        RkaSimpanan::factory()->create(['tahun' => 2026, 'uker_id' => self::UKER_A, 'cabang_id' => self::CABANG]);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/rka/simpanan/year/2025')
            ->assertOk();

        $this->assertSame(1, RkaSimpanan::query()->count());
        $this->assertSame(2026, (int) RkaSimpanan::query()->value('tahun'));
    }

    // --- Gerbang akses ---------------------------------------------------

    public function test_non_admin_ditolak_di_seluruh_endpoint_upload(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/upload/simpanan')->assertForbidden();
        $this->actingAs($ro)->getJson('/admin/upload/simpanan/riwayat')->assertForbidden();
        $this->actingAs($ro)->post('/admin/upload/simpanan', ['berkas' => $this->berkasCsv()])->assertForbidden();
        $this->actingAs($ro)->deleteJson('/admin/upload/simpanan/2026-08-05')->assertForbidden();
        $this->actingAs($ro)->get('/admin/rka/simpanan')->assertForbidden();
    }
}
