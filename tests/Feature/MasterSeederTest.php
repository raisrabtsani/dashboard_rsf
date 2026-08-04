<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\Uker;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    public function test_mengisi_region_dari_code_region_csv(): void
    {
        $this->assertDatabaseHas('region', [
            'id' => Region::OFFICE_ID,
            'nama' => 'RO Pekanbaru',
        ]);
    }

    public function test_mengisi_area_dari_pasangan_unik_di_peta_area_csv(): void
    {
        $this->assertSame(3, Area::query()->count());

        $this->assertSame([
            1 => 'Dumai',
            2 => 'Pekanbaru Tuanku Tambusai',
            3 => 'Batam Center',
        ], Area::query()->orderBy('id')->pluck('nama', 'id')->all());
    }

    public function test_satu_cabang_per_id_cabang_unik_plus_rollup_region(): void
    {
        // 22 BO di code_uker.csv + 1 baris bayangan rollup 855.
        $this->assertSame(23, Cabang::query()->count());
        $this->assertSame(22, Cabang::tanpaRegionOffice()->count());
    }

    public function test_cabang_mendapat_area_id_hasil_join_peta_area(): void
    {
        $batamCenter = Cabang::query()->find(621);

        $this->assertSame('BO Batam Center', $batamCenter->nama);
        $this->assertSame(Region::OFFICE_ID, $batamCenter->region_id);
        $this->assertSame(3, $batamCenter->area_id);
        $this->assertSame('Batam Center', $batamCenter->area->nama);
        $this->assertSame('RO Pekanbaru', $batamCenter->region->nama);
    }

    public function test_satu_uker_per_baris_code_uker_csv_plus_rollup_region(): void
    {
        $this->assertSame(234, Uker::query()->count());
        $this->assertSame(233, Uker::tanpaRegionOffice()->count());
    }

    public function test_tipe_uker_diturunkan_dari_id_dan_nama(): void
    {
        // id_uker == id_cabang -> BO, walau namanya diawali "BO ".
        $this->assertSame(Uker::TIPE_BO, Uker::query()->find(159)->tipe);
        $this->assertSame(Uker::TIPE_SBO, Uker::query()->find(2264)->tipe);
        $this->assertSame(Uker::TIPE_UNIT, Uker::query()->find(5438)->tipe);
        $this->assertSame(Uker::TIPE_KK, Uker::query()->find(1622)->tipe);
    }

    public function test_jumlah_bo_sama_dengan_jumlah_cabang_operasional(): void
    {
        $this->assertSame(
            Cabang::tanpaRegionOffice()->count(),
            Uker::query()->where('tipe', Uker::TIPE_BO)->count(),
        );
    }

    public function test_semua_uker_menempel_ke_cabang_yang_ada(): void
    {
        $yatim = Uker::query()
            ->whereNotIn('cabang_id', Cabang::query()->select('id'))
            ->count();

        $this->assertSame(0, $yatim);
    }

    public function test_membuat_baris_master_855_untuk_cabang_dan_uker(): void
    {
        // 855 tidak ada di code_uker.csv, tapi wajib ada di master supaya import
        // segmen level Region (mis. Pinjaman Medium) lolos validasi FK.
        $cabang = Cabang::query()->find(Region::OFFICE_ID);
        $uker = Uker::query()->find(Region::OFFICE_ID);

        $this->assertNotNull($cabang);
        $this->assertSame(Region::OFFICE_ID, $cabang->region_id);
        $this->assertNull($cabang->area_id);

        $this->assertNotNull($uker);
        $this->assertSame(Region::OFFICE_ID, $uker->cabang_id);
        $this->assertSame(Uker::TIPE_REGION, $uker->tipe);
    }

    public function test_rollup_855_disembunyikan_oleh_scope_tanpa_region_office(): void
    {
        $this->assertNotContains(Region::OFFICE_ID, Cabang::tanpaRegionOffice()->pluck('id')->all());
        $this->assertNotContains(Region::OFFICE_ID, Uker::tanpaRegionOffice()->pluck('id')->all());
    }

    public function test_relasi_berjenjang_region_cabang_uker(): void
    {
        $uker = Uker::query()->with('cabang', 'area', 'region')->find(7351);

        $this->assertSame(1079, $uker->cabang->id);
        $this->assertSame('Pekanbaru Tuanku Tambusai', $uker->area->nama);
        $this->assertSame('RO Pekanbaru', $uker->region->nama);

        $this->assertSame(7, Area::query()->find(1)->cabang()->count());
        $this->assertSame(23, Region::query()->find(Region::OFFICE_ID)->cabang()->count());
        $this->assertSame(234, Region::query()->find(Region::OFFICE_ID)->uker()->count());
    }

    public function test_id_master_tidak_auto_increment(): void
    {
        $cabang = Cabang::query()->create([
            'id' => 9999,
            'region_id' => Region::OFFICE_ID,
            'area_id' => null,
            'nama' => 'BO Uji',
        ]);

        $this->assertSame(9999, $cabang->refresh()->id);
        $this->assertFalse($cabang->incrementing);
    }

    public function test_seeder_idempoten_saat_dijalankan_ulang(): void
    {
        $sebelum = [Region::query()->count(), Area::query()->count(), Cabang::query()->count(), Uker::query()->count()];

        $this->seed(MasterSeeder::class);

        $sesudah = [Region::query()->count(), Area::query()->count(), Cabang::query()->count(), Uker::query()->count()];

        $this->assertSame($sebelum, $sesudah);
    }
}
