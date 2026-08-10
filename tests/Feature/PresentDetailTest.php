<?php

namespace Tests\Feature;

use App\Models\Pinjaman;
use App\Models\RkaPinjaman;
use App\Services\PresentService;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi tabel detail PRESENT: rincian wajib mengikuti segmen, segmentasi,
 * kualitas kredit, dan menyertakan tabel rasio %NPL.
 */
class PresentDetailTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    private const UKER = 5438;

    private const POSISI = '2026-08-04';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);

        $this->pinjaman('Micro', 'Kupedes Komersial', Pinjaman::KUALITAS_LANCAR, 100);
        $this->pinjaman('Micro', 'Briguna Mikro', Pinjaman::KUALITAS_SML, 10);
        $this->pinjaman('Small', 'Non Cash Collateral', Pinjaman::KUALITAS_NPL, 20);
        $this->pinjaman('Consumer', 'Briguna', Pinjaman::KUALITAS_SML, 30);
        $this->pinjaman('Consumer', 'KPR', Pinjaman::KUALITAS_NPL, 40);

        $this->rka('Micro', 'Kupedes Komersial', Pinjaman::KUALITAS_LANCAR, 100);
        $this->rka('Micro', 'Briguna Mikro', Pinjaman::KUALITAS_SML, 10);
        $this->rka('Small', 'Non Cash Collateral', Pinjaman::KUALITAS_NPL, 20);
        $this->rka('Consumer', 'Briguna', Pinjaman::KUALITAS_SML, 30);
        $this->rka('Consumer', 'KPR', Pinjaman::KUALITAS_NPL, 40);
    }

    private function pinjaman(string $segmen, string $segmentasi, string $kualitas, float $juta): void
    {
        Pinjaman::factory()->create([
            'cabang_id' => self::CABANG,
            'uker_id' => self::UKER,
            'segmen' => $segmen,
            'segmentasi' => $segmentasi,
            'kualitas' => $kualitas,
            'tanggal' => self::POSISI,
            'baki_debet' => $juta * 1_000_000,
        ]);
    }

    private function rka(string $segmen, string $segmentasi, string $kualitas, float $juta): void
    {
        RkaPinjaman::factory()->create([
            'cabang_id' => self::CABANG,
            'uker_id' => self::UKER,
            'segmen' => $segmen,
            'segmentasi' => $segmentasi,
            'kualitas' => $kualitas,
            'tahun' => 2026,
            'bulan' => 8,
            'target' => $juta * 1_000_000,
        ]);
    }

    /** @return array<string, mixed> */
    private function tabel(array $detail, string $judul): array
    {
        return collect($detail['tabel'])->firstWhere('judul', $judul);
    }

    /** @return array<string, mixed> */
    private function baris(array $tabel, string $label): array
    {
        return collect($tabel['baris'])
            ->first(fn (array $baris) => ($baris['kind'] ?? null) === 'item' && $baris['label'] === $label);
    }

    public function test_detail_pinjaman_memisahkan_segmen_dan_segmentasi(): void
    {
        $detail = app(PresentService::class)->detail(self::POSISI);
        $pinjaman = $this->tabel($detail, 'Detail Pinjaman');

        $this->assertSame(110.0, (float) $this->baris($pinjaman, 'Mikro')['current']);
        $this->assertSame(20.0, (float) $this->baris($pinjaman, 'Small')['current']);
        $this->assertSame(70.0, (float) $this->baris($pinjaman, 'Consumer')['current']);
        $this->assertSame(30.0, (float) $this->baris($pinjaman, 'Briguna')['current']);
        $this->assertSame(40.0, (float) $this->baris($pinjaman, 'KPR')['current']);
        $this->assertSame(100.0, (float) $this->baris($pinjaman, 'Kupedes Komersial')['current']);
    }

    public function test_detail_sml_npl_dan_rasionya_terpisah(): void
    {
        $detail = app(PresentService::class)->detail(self::POSISI);

        $sml = $this->tabel($detail, 'Detail SML');
        $npl = $this->tabel($detail, 'Detail NPL');
        $persenSml = $this->tabel($detail, 'Detail % SML');
        $persenNpl = $this->tabel($detail, 'Detail % NPL');

        $this->assertSame(40.0, (float) $this->baris($sml, 'Total Pinjaman')['current']);
        $this->assertSame(60.0, (float) $this->baris($npl, 'Total Pinjaman')['current']);
        $this->assertSame(20.0, (float) $this->baris($persenSml, 'Total Pinjaman')['current']);
        $this->assertSame(30.0, (float) $this->baris($persenNpl, 'Total Pinjaman')['current']);

        $this->assertSame(30.0, (float) $this->baris($sml, 'Briguna')['current']);
        $this->assertSame(40.0, (float) $this->baris($npl, 'KPR')['current']);
    }
}
