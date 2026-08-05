<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\Uker;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Query & kalkulasi manajemen user untuk area Admin.
 *
 * Konsep 1 user = 1 kantor: menambah user berarti mendaftarkan kantornya lewat
 * cabang_id/uker_id.
 */
class UserAdminService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(?string $cari, ?int $cabangId, ?string $tipe): array
    {
        return User::query()
            ->with(['cabang:id,nama', 'uker:id,nama'])
            ->when($cari, fn ($q) => $q->where(function ($q) use ($cari) {
                $q->where('username', 'like', "%{$cari}%")->orWhere('name', 'like', "%{$cari}%");
            }))
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
            ->orderBy('username')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'name' => $u->name,
                'role' => $u->role,
                'tipe' => $u->tipe,
                'access_level' => $u->access_level,
                'is_locked' => $u->is_locked,
                'cabang_id' => $u->cabang_id,
                'uker_id' => $u->uker_id,
                'cabang' => $u->cabang?->nama,
                'uker' => $u->uker?->nama,
                'terakhir_diubah' => $u->updated_at === null ? null : Carbon::parse($u->updated_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function statistik(): array
    {
        return [
            'total' => User::query()->count(),
            'admin' => User::query()->where('role', User::ROLE_ADMIN)->count(),
            'terkunci' => User::query()->where('is_locked', true)->count(),
            'per_tipe' => User::query()
                ->groupBy('tipe')
                ->orderBy('tipe')
                ->selectRaw('tipe, COUNT(*) as jumlah')
                ->pluck('jumlah', 'tipe')
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opsiForm(): array
    {
        return [
            'cabang' => Cabang::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'tipe' => [User::TIPE_RO, User::TIPE_BO, User::TIPE_SBO, User::TIPE_UNIT, User::TIPE_KK],
            'role' => [User::ROLE_USER, User::ROLE_ADMIN],
        ];
    }

    /**
     * @return list<array{id: int, nama: string, tipe: string}>
     */
    public function ukerPerCabang(int $cabangId): array
    {
        return Uker::query()
            ->where('cabang_id', $cabangId)
            ->orderBy('nama')
            ->get(['id', 'nama', 'tipe'])
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function simpan(array $data): User
    {
        return User::query()->create($data);
    }

    /**
     * Perbarui user; password kosong berarti TIDAK diubah.
     *
     * @param  array<string, mixed>  $data
     */
    public function perbarui(User $user, array $data): User
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return $user->refresh();
    }
}
