<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aktivitas pengguna untuk area Admin.
 *
 * DUA sumber yang saling melengkapi:
 *  - "Online sekarang" → dibaca dari tabel `sessions` (butuh SESSION_DRIVER=
 *    database): sesi dengan last_activity dalam AMBANG_ONLINE_MENIT terakhir.
 *  - "Terakhir aktif" per user → kolom `users.last_seen_at` yang diperbarui
 *    middleware TrackUserActivity (throttle 60 detik).
 *
 * Query builder biasa, portable MySQL/SQLite (last_activity = unix timestamp).
 */
class UserActivityService
{
    /** Sesi dianggap "online" bila aktivitasnya dalam rentang ini. */
    public const AMBANG_ONLINE_MENIT = 5;

    /** @var array<string, mixed>|null Memo online() dalam satu request. */
    private ?array $onlineMemo = null;

    /**
     * User yang online sekarang, diturunkan dari tabel sessions.
     *
     * Satu user bisa punya beberapa sesi (mis. dua perangkat); di-dedupe per
     * user dengan mengambil aktivitas terakhirnya. Dimemo per instance karena
     * daftar() & statistik() ikut memakainya.
     *
     * @return array<string, mixed>
     */
    public function online(): array
    {
        if ($this->onlineMemo !== null) {
            return $this->onlineMemo;
        }

        $ambang = Carbon::now()->subMinutes(self::AMBANG_ONLINE_MENIT)->getTimestamp();

        $sesi = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $ambang)
            ->get(['user_id', 'ip_address', 'last_activity']);

        // Aktivitas terakhir + jumlah sesi per user.
        $perUser = $sesi
            ->groupBy('user_id')
            ->map(fn ($rows) => [
                'last_activity' => (int) $rows->max('last_activity'),
                'sesi' => $rows->count(),
                'ip' => $rows->sortByDesc('last_activity')->first()->ip_address,
            ]);

        $users = User::query()
            ->whereIn('id', $perUser->keys())
            ->get(['id', 'username', 'name', 'role', 'tipe'])
            ->keyBy('id');

        $hasil = $perUser
            ->map(function (array $info, $userId) use ($users) {
                $user = $users->get($userId);

                if ($user === null) {
                    return null;    // sesi milik user yang sudah dihapus
                }

                $waktu = Carbon::createFromTimestamp($info['last_activity']);

                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'role' => $user->role,
                    'tipe' => $user->tipe,
                    'ip' => $info['ip'],
                    'sesi' => $info['sesi'],
                    'terakhir_aktivitas' => $waktu->toDateTimeString(),
                    'menit_lalu' => (int) $waktu->diffInMinutes(Carbon::now()),
                ];
            })
            ->filter()
            ->sortBy('menit_lalu')
            ->values()
            ->all();

        return $this->onlineMemo = $hasil;
    }

    /**
     * Semua user dengan cap "terakhir aktif" + tanda online sekarang.
     *
     * @return array<string, mixed>
     */
    public function daftar(): array
    {
        $onlineIds = collect($this->online())->pluck('id')->all();

        return User::query()
            ->with(['cabang:id,nama', 'uker:id,nama'])
            ->orderByRaw('last_seen_at IS NULL')       // yang punya cap dulu
            ->orderByDesc('last_seen_at')
            ->orderBy('username')
            ->get(['id', 'username', 'name', 'role', 'tipe', 'cabang_id', 'uker_id', 'last_seen_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'name' => $u->name,
                'role' => $u->role,
                'tipe' => $u->tipe,
                'cabang' => $u->cabang?->nama,
                'uker' => $u->uker?->nama,
                'online' => in_array($u->id, $onlineIds, true),
                'terakhir_aktif' => $u->last_seen_at === null ? null : $u->last_seen_at->toDateTimeString(),
                'menit_lalu' => $u->last_seen_at === null ? null : $u->last_seen_at->diffInMinutes(Carbon::now()),
            ])
            ->all();
    }

    /**
     * Ringkasan angka untuk header kartu.
     *
     * @return array<string, int>
     */
    public function statistik(): array
    {
        return [
            'online' => count($this->online()),
            'total' => User::query()->count(),
            'ambang_menit' => self::AMBANG_ONLINE_MENIT,
        ];
    }
}
