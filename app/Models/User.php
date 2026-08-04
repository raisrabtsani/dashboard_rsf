<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Lihat semua data, semua filter bebas (admin & RO).
     */
    public const LEVEL_ALL = 'LEVEL_ALL';

    /**
     * Terkunci pada satu cabang; masih boleh drill-down uker di cabangnya (BO).
     */
    public const LEVEL_CABANG = 'LEVEL_CABANG';

    /**
     * Terkunci pada satu uker (SBO/UNIT/KK).
     */
    public const LEVEL_UKER = 'LEVEL_UKER';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const TIPE_RO = 'RO';

    public const TIPE_BO = 'BO';

    public const TIPE_SBO = 'SBO';

    public const TIPE_UNIT = 'UNIT';

    public const TIPE_KK = 'KK';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'tipe',
        'cabang_id',
        'uker_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * access_level tidak punya kolom sendiri — selalu diturunkan dari role & tipe,
     * dan ikut serta setiap kali user diserialisasi (termasuk ke props Inertia).
     *
     * @var list<string>
     */
    protected $appends = [
        'access_level',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'cabang_id' => 'integer',
            'uker_id' => 'integer',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function uker(): BelongsTo
    {
        return $this->belongsTo(Uker::class);
    }

    /**
     * Peta role & tipe user ke tiga level akses data.
     *
     * Default-nya sengaja LEVEL_UKER (paling sempit): tipe yang tidak dikenali
     * atau belum diisi TIDAK boleh berakhir bisa melihat semua data.
     */
    protected function accessLevel(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->role === self::ROLE_ADMIN => self::LEVEL_ALL,
            $this->tipe === self::TIPE_RO => self::LEVEL_ALL,
            $this->tipe === self::TIPE_BO => self::LEVEL_CABANG,
            default => self::LEVEL_UKER,
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function lihatSemuaData(): bool
    {
        return $this->access_level === self::LEVEL_ALL;
    }
}
