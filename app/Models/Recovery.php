<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recovery — penagihan atas kredit yang sudah dihapusbukukan.
 *
 * Nilainya `actual` (rupiah penuh), berdimensi SEGMEN. Tidak ada dimensi produk.
 */
class Recovery extends Model
{
    /** @use HasFactory<\Database\Factories\RecoveryFactory> */
    use HasFactory;

    public const SEGMEN_MICRO = 'Micro';

    public const SEGMEN_SME = 'SME';

    public const SEGMEN_CONSUMER = 'Consumer';

    /** Segmen KANONIK — urutan ini juga dipakai untuk mengurutkan kartu. */
    public const SEGMEN = [self::SEGMEN_MICRO, self::SEGMEN_SME, self::SEGMEN_CONSUMER];

    /**
     * Taksonomi MENTAH -> KANONIK. Satu-satunya sumber pemetaan segmen.
     *
     * Sumber data berubah antar tahun: mis. tahun lalu memakai "SME", tahun ini
     * dipecah jadi "Small" + "Medium". Data mentah disimpan apa adanya di DB;
     * pelipatan ke segmen kanonik dilakukan SAAT BACA (lihat kanonik()) supaya
     * perbandingan YoY apple-to-apple dan data historis tidak perlu diutak-atik.
     *
     * @var array<string, list<string>>
     */
    public const SEGMEN_RAW = [
        self::SEGMEN_MICRO => ['micro', 'mikro'],
        self::SEGMEN_SME => ['sme', 'small', 'medium', 'kecil', 'menengah'],
        self::SEGMEN_CONSUMER => ['consumer', 'konsumer', 'konsumtif'],
    ];

    protected $table = 'recovery';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'tanggal', 'actual'];

    /**
     * Lipat satu nilai segmen mentah ke segmen kanonik.
     *
     * Nilai yang tidak dikenal dikembalikan APA ADANYA (bukan dibuang) supaya
     * taksonomi baru yang belum terpetakan tetap muncul di dashboard dan mudah
     * ketahuan, bukan hilang diam-diam.
     */
    public static function kanonik(string $segmen): string
    {
        $kunci = mb_strtolower(trim($segmen));

        foreach (self::SEGMEN_RAW as $kanonik => $mentah) {
            if (in_array($kunci, $mentah, true)) {
                return $kanonik;
            }
        }

        return trim($segmen);
    }

    protected function casts(): array
    {
        return [
            'actual' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` sengaja BUKAN cast `date` — lihat App\Models\Simpanan untuk
     * alasannya (cast date menulis 'Y-m-d H:i:s'; MySQL memotong jamnya, SQLite
     * tidak, sehingga query tanggal berperilaku beda di produksi vs test).
     */
    protected function tanggal(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
            set: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function uker(): BelongsTo
    {
        return $this->belongsTo(Uker::class);
    }
}
