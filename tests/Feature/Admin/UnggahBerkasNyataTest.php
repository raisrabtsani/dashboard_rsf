<?php

namespace Tests\Feature\Admin;

use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Uji berkas SUNGGUHAN dari unit bisnis, lewat jalur unggahan HTTP penuh.
 *
 * Berkasnya tidak ikut di-commit (di luar repo, berisi angka bisnis), jadi tiap
 * test melewatkan diri bila berkasnya tidak ada — termasuk di CI.
 *
 * Angka acuannya dihitung terpisah dari berkas mentah, bukan disalin dari
 * keluaran importer — kalau tidak, test ini hanya mengukur dirinya sendiri.
 */
class UnggahBerkasNyataTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS_RKA = 'C:\Users\BRI\Downloads\Full Target Simpanan 2026.csv';

    private const BERKAS_AKTUAL = 'C:\Users\BRI\Downloads\08. Full Simpanan Agustus 2026.csv';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function lewatiBila(string $berkas): void
    {
        if (! is_file($berkas)) {
            $this->markTestSkipped('Berkas sungguhan tidak tersedia di lingkungan ini: '.basename($berkas));
        }
    }

    /**
     * Ditiru persis seperti unggahan browser: isi disalin ke berkas sementara
     * PHP (berekstensi .tmp), nama asli dikirim terpisah.
     */
    private function unggah(string $berkas, string $endpoint): \Illuminate\Testing\TestResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy($berkas, $tmp);

        return $this->actingAs(User::factory()->admin()->create())
            ->post($endpoint, [
                'berkas' => new UploadedFile($tmp, basename($berkas), 'text/csv', null, true),
            ]);
    }

    public function test_berkas_rka_sungguhan_terunggah_lewat_http(): void
    {
        $this->lewatiBila(self::BERKAS_RKA);

        $respons = $this->unggah(self::BERKAS_RKA, '/admin/rka/simpanan')->assertOk();

        $this->assertSame(7512, $respons->json('hasil.baris'));
        $this->assertSame(117, $respons->json('hasil.dilewati'));
        $this->assertSame([2026], $respons->json('hasil.tahun'));

        $this->assertSame(7512, RkaSimpanan::query()->count());
        $this->assertSame(448_426_481_371_602.0, (float) RkaSimpanan::query()->sum('target'));

        $this->assertEqualsCanonicalizing(
            ['Ritel', 'Micro'],
            RkaSimpanan::query()->distinct()->pluck('segmentasi')->all(),
        );
        $this->assertSame(
            range(1, 12),
            RkaSimpanan::query()->distinct()->orderBy('bulan')->pluck('bulan')->all(),
        );
    }

    public function test_berkas_aktual_sungguhan_terunggah_lewat_http(): void
    {
        $this->lewatiBila(self::BERKAS_AKTUAL);

        $respons = $this->unggah(self::BERKAS_AKTUAL, '/admin/upload/simpanan')->assertOk();

        // 19.701 baris rekening -> 2.043 baris posisi (681 per tanggal x 3).
        $this->assertSame(19_701, $respons->json('hasil.sumber'));
        $this->assertSame(2_043, $respons->json('hasil.baris'));
        $this->assertSame(
            ['2026-08-01', '2026-08-02', '2026-08-03'],
            $respons->json('hasil.tanggal'),
        );

        $this->assertSame(2_043, Simpanan::query()->count());

        // Total per tanggal dihitung manual dari berkas mentah.
        $perTanggal = Simpanan::query()
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(saldo) as total')
            ->pluck('total', 'tanggal')
            ->map(fn ($v) => round((float) $v / 1_000_000))
            ->all();

        $this->assertSame([
            '2026-08-01' => 40_381_685.0,
            '2026-08-02' => 40_210_538.0,
            '2026-08-03' => 40_111_787.0,
        ], $perTanggal);

        // Header "Month, Day, Year of Posisi" berisi M/D/Y — semuanya Agustus.
        $this->assertSame(0, Simpanan::query()->where('tanggal', '<', '2026-08-01')->count());
    }
}
