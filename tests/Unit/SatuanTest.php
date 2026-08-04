<?php

namespace Tests\Unit;

use App\Support\Satuan;
use PHPUnit\Framework\TestCase;

class SatuanTest extends TestCase
{
    public function test_membagi_rupiah_penuh_menjadi_juta(): void
    {
        $this->assertSame(1.0, Satuan::toJuta(1_000_000));
        $this->assertSame(1234.5, Satuan::toJuta(1_234_500_000));
    }

    public function test_menerima_string_dan_float_dari_driver_database(): void
    {
        $this->assertSame(2.5, Satuan::toJuta('2500000'));
        $this->assertSame(2.5, Satuan::toJuta(2500000.0));
    }

    public function test_null_tetap_null_bukan_nol(): void
    {
        $this->assertNull(Satuan::toJuta(null));
        $this->assertNull(Satuan::toJuta(''));
        $this->assertSame(0.0, Satuan::toJuta(0));
    }

    public function test_presisi_membulatkan_bila_diminta(): void
    {
        $this->assertSame(1.23, Satuan::toJuta(1_234_567, 2));
        $this->assertSame(1.2345670, Satuan::toJuta(1_234_567));
    }
}
