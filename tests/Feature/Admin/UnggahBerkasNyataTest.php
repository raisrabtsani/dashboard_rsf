<?php

namespace Tests\Feature\Admin;

use App\Models\RkaSimpanan;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Uji berkas RKA SUNGGUHAN dari unit bisnis, lewat jalur unggahan HTTP penuh.
 *
 * Berkasnya tidak ikut di-commit (di luar repo, berisi angka bisnis), jadi test
 * ini melewatkan diri bila berkasnya tidak ada — termasuk di CI.
 */
class UnggahBerkasNyataTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS = 'C:\Users\BRI\Downloads\Full Target Simpanan 2026.csv';

    /** Angka acuan hasil pemeriksaan manual atas berkas tersebut. */
    private const BARIS_DIHARAPKAN = 7512;

    private const DILEWATI_DIHARAPKAN = 117;

    private const TOTAL_DIHARAPKAN = 448_426_481_371_602.0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(self::BERKAS)) {
            $this->markTestSkipped('Berkas RKA sungguhan tidak tersedia di lingkungan ini.');
        }

        $this->seed(MasterSeeder::class);
    }

    public function test_berkas_rka_sungguhan_terunggah_lewat_http(): void
    {
        // Ditiru persis seperti unggahan browser: isi disalin ke berkas
        // sementara PHP (berekstensi .tmp), nama asli dikirim terpisah.
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy(self::BERKAS, $tmp);

        $berkas = new UploadedFile($tmp, basename(self::BERKAS), 'text/csv', null, true);

        $respons = $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/rka/simpanan', ['berkas' => $berkas]);

        $respons->assertOk();

        $this->assertSame(self::BARIS_DIHARAPKAN, $respons->json('hasil.baris'));
        $this->assertSame(self::DILEWATI_DIHARAPKAN, $respons->json('hasil.dilewati'));
        $this->assertSame([2026], $respons->json('hasil.tahun'));

        $this->assertSame(self::BARIS_DIHARAPKAN, RkaSimpanan::query()->count());
        $this->assertSame(self::TOTAL_DIHARAPKAN, (float) RkaSimpanan::query()->sum('target'));

        // Dimensi dari berkas tidak hilang.
        $this->assertEqualsCanonicalizing(
            ['Ritel', 'Micro'],
            RkaSimpanan::query()->distinct()->pluck('segmentasi')->all(),
        );
        $this->assertSame(
            range(1, 12),
            RkaSimpanan::query()->distinct()->orderBy('bulan')->pluck('bulan')->all(),
        );
    }
}
