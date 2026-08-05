<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'opsi' => $this->service->opsiForm(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json([
            'users' => $this->service->daftar(
                $request->string('cari')->toString() ?: null,
                $request->integer('cabang_id') ?: null,
                $request->string('tipe')->toString() ?: null,
            ),
            'statistik' => $this->service->statistik(),
        ]);
    }

    public function uker(int $cabangId): JsonResponse
    {
        return response()->json($this->service->ukerPerCabang($cabangId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validasi($request);

        $this->service->simpan($data);

        return response()->json(['message' => "User {$data['username']} dibuat."], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validasi($request, $user);

        $this->service->perbarui($user, $data);

        return response()->json(['message' => "User {$user->username} diperbarui."]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'Tidak bisa menghapus akun sendiri.');

        $user->delete();

        return response()->json(['message' => "User {$user->username} dihapus."]);
    }

    /**
     * Kunci/buka akun. User terkunci ditolak login walau kredensialnya benar.
     */
    public function toggleLock(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'Tidak bisa mengunci akun sendiri.');

        $user->update(['is_locked' => ! $user->is_locked]);

        return response()->json([
            'message' => $user->is_locked
                ? "Akun {$user->username} dikunci."
                : "Akun {$user->username} dibuka.",
            'is_locked' => $user->is_locked,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validasi(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'username' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'tipe' => ['required', Rule::in([
                User::TIPE_RO, User::TIPE_BO, User::TIPE_SBO, User::TIPE_UNIT, User::TIPE_KK,
            ])],
            'cabang_id' => ['required', 'integer', 'exists:cabang,id'],
            'uker_id' => ['required', 'integer', 'exists:uker,id'],
            'is_locked' => ['boolean'],
            // Dikosongkan saat edit = password tidak diubah.
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:8'],
        ]);
    }
}
