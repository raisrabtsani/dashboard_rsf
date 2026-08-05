<?php

namespace Tests\Feature;

use App\Models\Simpanan;
use App\Models\SimpananHourly;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SimpananHourlyTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    private const UKER = 5438;

    private const CABANG_LAIN = 621;

    private const UKER_LAIN = 5516;

    /** 31 Agustus 2026 = akhir bulan. */
    private const EOM = '2026-08-31';

    private const JT = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function jam(string $produk, int $jam, float $juta, int $cabang = self::CABANG, int $uker = self::UKER): void
    {
        SimpananHourly::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'produk' => $produk,
            'segmentasi' => 'Ritel',
            'tanggal' => self::EOM,
            'jam' => $jam,
            'saldo' => $juta * self::JT,
        ]);
    }

    private function harian(string $produk, string $tanggal, float $juta, int $cabang = self::CABANG, int $uker = self::UKER): void
    {
        Simpanan::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'produk' => $produk,
            'segmentasi' => 'Ritel',
            'tanggal' => $tanggal,
            'saldo' => $juta * self::JT,
        ]);
    }

    /**
     * Posisi jam 10 & 14 pada 31 Agu, plus baseline harian 30 Agu.
     */
    private function skenario(): void
    {
        // Baseline HARIAN (H-1).
        $this->harian('Tabungan', '2026-08-30', 100);
        $this->harian('Giro', '2026-08-30', 40);
        $this->harian('Deposito', '2026-08-30', 60);

        // Jam 10.
        $this->jam('Tabungan', 10, 110);
        $this->jam('Giro', 10, 45);
        $this->jam('Deposito', 10, 60);

        // Jam 14.
        $this->jam('Tabungan', 14, 130);
        $this->jam('Giro', 14, 50);
        $this->jam('Deposito', 14, 65);
    }

    private function ro(): User
    {
        return User::factory()->ro()->create();
    }

    private function snapshot(User $user, array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/simpanan-hourly/snapshot?'.http_build_query($filter + ['tanggal' => self::EOM]))
            ->assertOk()
            ->json();
    }

    private function kartu(array $snapshot, string $key): array
    {
        $k = collect($snapshot['kartu'])->firstWhere('key', $key);

        foreach (['nilai', 'baseline'] as $f) {
            $k[$f] = $k[$f] === null ? null : (float) $k[$f];
        }
        $k['delta']['dtd']['nilai'] = $k['delta']['dtd']['nilai'] === null
            ? null
            : (float) $k['delta']['dtd']['nilai'];

        return $k;
    }

    // --- Perhitungan ------------------------------------------------------

    public function test_tanpa_jam_memakai_jam_terakhir_yang_tersedia(): void
    {
        $this->skenario();

        $snapshot = $this->snapshot($this->ro());

        $this->assertSame(14, $snapshot['jam']);
        $this->assertSame([10, 14], $snapshot['jam_tersedia']);
        $this->assertSame(245.0, $this->kartu($snapshot, 'total')['nilai']);   // 130+50+65
    }

    public function test_jam_bisa_dipilih_eksplisit(): void
    {
        $this->skenario();

        $snapshot = $this->snapshot($this->ro(), ['jam' => 10]);

        $this->assertSame(10, $snapshot['jam']);
        $this->assertSame(215.0, $this->kartu($snapshot, 'total')['nilai']);   // 110+45+60
        $this->assertSame(155.0, $this->kartu($snapshot, 'casa')['nilai']);    // 110+45
    }

    public function test_baseline_memakai_tabel_harian_bukan_jam_sebelumnya(): void
    {
        $this->skenario();

        $snapshot = $this->snapshot($this->ro(), ['jam' => 14]);

        // Pembandingnya posisi HARIAN 30 Agu (200), bukan jam 10 (215).
        $this->assertSame('2026-08-30', $snapshot['tanggal_baseline']);

        $total = $this->kartu($snapshot, 'total');
        $this->assertSame(200.0, $total['baseline']);
        $this->assertSame(45.0, $total['delta']['dtd']['nilai']);   // 245 − 200

        // Kalau baselinenya jam 10, deltanya akan 30 — pastikan BUKAN itu.
        $this->assertNotSame(30.0, $total['delta']['dtd']['nilai']);
    }

    public function test_tanpa_data_harian_delta_null_bukan_nol(): void
    {
        // Hanya data per jam, tidak ada baseline harian sama sekali.
        $this->jam('Tabungan', 10, 110);

        $snapshot = $this->snapshot($this->ro());

        $this->assertNull($snapshot['tanggal_baseline']);
        $this->assertNull($this->kartu($snapshot, 'total')['delta']['dtd']['nilai']);
    }

    public function test_snapshot_tidak_memuat_rka(): void
    {
        $this->skenario();

        $snapshot = $this->snapshot($this->ro());

        $this->assertFalse($snapshot['punya_rka']);

        foreach ($snapshot['kartu'] as $k) {
            $this->assertArrayNotHasKey('target', $k);
            $this->assertArrayNotHasKey('pencapaian', $k);
        }
    }

    public function test_chart_menyusun_tren_antar_jam(): void
    {
        $this->skenario();

        $data = $this->actingAs($this->ro())
            ->getJson('/api/simpanan-hourly/chart?tanggal='.self::EOM)
            ->assertOk()
            ->json();

        $this->assertSame([10, 14], $data['jam']);
        $this->assertSame(['10:00', '14:00'], $data['label']);

        $total = collect($data['seri'])->firstWhere('key', 'total');
        $this->assertSame([215.0, 245.0], array_map('floatval', $total['titik']));
    }

    public function test_tabel_cabang_membandingkan_dengan_posisi_harian(): void
    {
        $this->skenario();
        // Cabang lain, hanya per jam.
        $this->jam('Tabungan', 14, 500, self::CABANG_LAIN, self::UKER_LAIN);

        $data = $this->actingAs($this->ro())
            ->getJson('/api/simpanan-hourly/branch-pencapaian?tanggal='.self::EOM)
            ->assertOk()
            ->json();

        $this->assertSame('cabang', $data['grouping']);
        $this->assertSame(14, $data['jam']);

        $baris = collect($data['baris'])->keyBy('id');
        $this->assertSame(245.0, (float) $baris[self::CABANG]['nilai']);
        $this->assertSame(200.0, (float) $baris[self::CABANG]['baseline']);
        // Cabang lain tak punya baseline harian -> delta null, bukan 0.
        $this->assertNull($baris[self::CABANG_LAIN]['baseline']);
        $this->assertNull($baris[self::CABANG_LAIN]['delta']['nilai']);
    }

    // --- Gerbang akses `hourly` -------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function endpointHourly(): array
    {
        return [
            ['/dashboard/simpanan-hourly'],
            ['/api/simpanan-hourly/filter-options'],
            ['/api/simpanan-hourly/snapshot'],
            ['/api/simpanan-hourly/chart'],
            ['/api/simpanan-hourly/branch-pencapaian'],
            ['/api/simpanan-hourly/cabang/1'],
            ['/api/simpanan-hourly/uker/159'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpointHourly')]
    public function test_user_level_uker_ditolak_di_semua_endpoint(string $uri): void
    {
        // Bukan sekadar menu disembunyikan — endpointnya sendiri menolak.
        $uker = User::factory()->uker(self::CABANG, self::UKER)->create();

        $this->actingAs($uker)->get($uri)->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpointHourly')]
    public function test_ro_bo_dan_admin_diizinkan(string $uri): void
    {
        foreach ([
            User::factory()->ro()->create(),
            User::factory()->bo(self::CABANG)->create(),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user)->get($uri)->assertOk();
        }
    }

    public function test_tamu_tetap_diarahkan_ke_login(): void
    {
        $this->get('/dashboard/simpanan-hourly')->assertRedirect('/login');
    }

    public function test_scope_tetap_berlaku_untuk_user_bo(): void
    {
        $this->skenario();
        $this->jam('Tabungan', 14, 500, self::CABANG_LAIN, self::UKER_LAIN);

        $bo = User::factory()->bo(self::CABANG)->create();

        // Minta cabang lain — tetap dapat angka cabangnya sendiri.
        $snapshot = $this->snapshot($bo, ['cabang_id' => self::CABANG_LAIN]);

        $this->assertSame(245.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    // --- Upload -----------------------------------------------------------

    private function unggah(int $jam, array $baris, ?User $user = null): TestResponse
    {
        $isi = "id_cabang,id_uker,produk,segmentasi,tanggal,saldo\n";

        foreach ($baris as $b) {
            $isi .= implode(',', $b)."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $isi);

        return $this->actingAs($user ?? User::factory()->admin()->create())
            ->post('/admin/upload/simpanan-hourly', [
                'berkas' => new UploadedFile($path, 'hourly.csv', 'text/csv', null, true),
                'jam' => $jam,
            ]);
    }

    public function test_jam_diambil_dari_form_bukan_dari_berkas(): void
    {
        $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 5_000_000],
        ])->assertOk();

        $this->assertSame(9, SimpananHourly::query()->value('jam'));
    }

    public function test_unggah_ulang_jam_yang_sama_menimpa_bukan_menggandakan(): void
    {
        $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 5_000_000],
        ])->assertOk();

        $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 7_000_000],
        ])->assertOk();

        $this->assertSame(1, SimpananHourly::query()->count());
        $this->assertSame(7_000_000.0, (float) SimpananHourly::query()->value('saldo'));
    }

    public function test_jam_berbeda_berdampingan(): void
    {
        $baris = [[self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 5_000_000]];

        $this->unggah(9, $baris)->assertOk();
        $this->unggah(10, $baris)->assertOk();

        $this->assertSame(2, SimpananHourly::query()->count());
        $this->assertSame([9, 10], SimpananHourly::query()->orderBy('jam')->pluck('jam')->all());
    }

    public function test_baris_rekening_dijumlahkan(): void
    {
        $respons = $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 1_000_000],
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 2_000_000],
        ])->assertOk();

        $this->assertSame(1, SimpananHourly::query()->count());
        $this->assertSame(2, $respons->json('hasil.sumber'));
        $this->assertSame(3_000_000.0, (float) SimpananHourly::query()->value('saldo'));
    }

    public function test_tanggal_bukan_eom_diperingatkan_tapi_tetap_masuk(): void
    {
        $respons = $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', '2026-08-15', 5_000_000],
        ])->assertOk();

        $this->assertSame(['2026-08-15'], $respons->json('hasil.bukan_eom'));
        $this->assertStringContainsString('bukan tanggal akhir bulan', $respons->json('message'));
        $this->assertSame(1, SimpananHourly::query()->count());
    }

    public function test_jam_wajib_diisi_dan_valid(): void
    {
        $this->unggah(99, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 5_000_000],
        ])->assertSessionHasErrors('jam');
    }

    public function test_upload_hanya_untuk_admin(): void
    {
        $this->unggah(9, [
            [self::CABANG, self::UKER, 'Tabungan', 'Ritel', self::EOM, 5_000_000],
        ], User::factory()->ro()->create())->assertForbidden();

        $this->assertSame(0, SimpananHourly::query()->count());
    }
}
