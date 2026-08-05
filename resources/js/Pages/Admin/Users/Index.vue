<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchUkerPerCabang,
    fetchUsers,
    hapusUser,
    perbaruiUser,
    simpanUser,
    toggleKunci,
} from '@/services/adminUserApi';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    opsi: { type: Object, required: true },
});

const akuSendiri = computed(() => usePage().props.auth?.user?.id ?? null);

const users = ref([]);
const statistik = ref({ total: 0, admin: 0, terkunci: 0, per_tipe: {} });
const memuat = ref(false);
const pesan = ref(null);

const filter = reactive({ cari: '', cabang_id: null, tipe: null });

const sort = useTableSort('username', 'asc');
const usersTerurut = computed(() => sort.urutkan(users.value));

const KOLOM = [
    { key: 'username', label: 'Username' },
    { key: 'name', label: 'Nama' },
    { key: 'tipe', label: 'Tipe' },
    { key: 'role', label: 'Role' },
    { key: 'cabang', label: 'Cabang' },
    { key: 'uker', label: 'Uker' },
];

// --- Form ----------------------------------------------------------------

const kosong = () => ({
    id: null,
    username: '',
    name: '',
    role: 'user',
    tipe: 'UNIT',
    cabang_id: null,
    uker_id: null,
    password: '',
});

const form = reactive(kosong());
const formTampil = ref(false);
const ukerOpsi = ref([]);
const errors = ref({});
const menyimpan = ref(false);

const sedangEdit = computed(() => form.id !== null);

function lapor(teks, jenis = 'sukses') {
    pesan.value = { teks, jenis };
}

async function muat() {
    memuat.value = true;
    try {
        const data = await fetchUsers(filter);
        users.value = data.users;
        statistik.value = data.statistik;
    } finally {
        memuat.value = false;
    }
}

function bukaBaru() {
    Object.assign(form, kosong());
    ukerOpsi.value = [];
    errors.value = {};
    formTampil.value = true;
}

async function bukaEdit(u) {
    Object.assign(form, {
        id: u.id,
        username: u.username,
        name: u.name,
        role: u.role,
        tipe: u.tipe,
        cabang_id: u.cabang_id,
        uker_id: u.uker_id,
        // Sengaja kosong: dibiarkan kosong = password tidak diubah.
        password: '',
    });
    errors.value = {};
    formTampil.value = true;
    ukerOpsi.value = u.cabang_id ? await fetchUkerPerCabang(u.cabang_id) : [];
}

// Cascading cabang -> uker. Saat edit awal, uker_id sudah diisi lebih dulu
// sehingga watcher tidak boleh mengosongkannya secara membabi buta.
watch(
    () => form.cabang_id,
    async (cabangId, sebelumnya) => {
        if (sebelumnya !== undefined && cabangId !== sebelumnya) form.uker_id = null;
        ukerOpsi.value = cabangId ? await fetchUkerPerCabang(cabangId) : [];
    },
);

async function simpan() {
    menyimpan.value = true;
    errors.value = {};

    const payload = { ...form };
    if (!payload.password) delete payload.password;

    try {
        const hasil = sedangEdit.value
            ? await perbaruiUser(form.id, payload)
            : await simpanUser(payload);

        lapor(hasil.message);
        formTampil.value = false;
        await muat();
    } catch (e) {
        if (e?.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
        } else {
            lapor(e?.response?.data?.message ?? 'Gagal menyimpan.', 'gagal');
        }
    } finally {
        menyimpan.value = false;
    }
}

async function kunci(u) {
    const aksi = u.is_locked ? 'membuka' : 'mengunci';
    if (!confirm(`Yakin ${aksi} akun ${u.username}?`)) return;

    try {
        lapor((await toggleKunci(u.id)).message);
        await muat();
    } catch (e) {
        lapor(e?.response?.data?.message ?? 'Gagal mengubah status akun.', 'gagal');
    }
}

async function hapus(u) {
    if (!confirm(`Hapus akun ${u.username}? Tindakan ini tidak bisa dibatalkan.`)) return;

    try {
        lapor((await hapusUser(u.id)).message);
        await muat();
    } catch (e) {
        lapor(e?.response?.data?.message ?? 'Gagal menghapus.', 'gagal');
    }
}

onMounted(muat);
</script>

<template>
    <Head title="Manajemen User" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Admin — Manajemen User
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="pesan"
                    class="rounded-md p-4 text-sm"
                    :class="
                        pesan.jenis === 'sukses'
                            ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                            : 'bg-rose-50 text-rose-800 ring-1 ring-rose-200'
                    "
                >
                    {{ pesan.teks }}
                </div>

                <!-- Statistik -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <p class="text-xs uppercase text-gray-500">Total User</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ statistik.total }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <p class="text-xs uppercase text-gray-500">Admin</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ statistik.admin }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <p class="text-xs uppercase text-gray-500">Akun Terkunci</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-rose-600">
                            {{ statistik.terkunci }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <p class="text-xs uppercase text-gray-500">Per Tipe</p>
                        <p class="mt-1 text-xs text-gray-600">
                            <span v-for="(jml, tipe) in statistik.per_tipe" :key="tipe" class="me-2">
                                {{ tipe }} <strong>{{ jml }}</strong>
                            </span>
                        </p>
                    </div>
                </div>

                <!-- Filter + tombol tambah -->
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Cari username / nama</span>
                            <input
                                v-model="filter.cari"
                                type="search"
                                class="mt-1 block w-56 rounded-md border-gray-300 text-sm"
                                @keyup.enter="muat"
                            />
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Cabang</span>
                            <select v-model="filter.cabang_id" class="mt-1 block rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Tipe</span>
                            <select v-model="filter.tipe" class="mt-1 block rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua</option>
                                <option v-for="t in opsi.tipe" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-gray-700 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800"
                            @click="muat"
                        >
                            Terapkan
                        </button>
                        <button
                            type="button"
                            class="ms-auto rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            @click="bukaBaru"
                        >
                            + Tambah User
                        </button>
                    </div>
                </div>

                <!-- Form -->
                <div v-if="formTampil" class="rounded-lg bg-white p-4 shadow ring-2 ring-indigo-200">
                    <h3 class="text-sm font-semibold text-gray-700">
                        {{ sedangEdit ? `Edit User: ${form.username}` : 'Tambah User Baru' }}
                    </h3>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Username</span>
                            <input v-model="form.username" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                            <span v-if="errors.username" class="text-xs text-rose-600">{{ errors.username[0] }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Nama</span>
                            <input v-model="form.name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                            <span v-if="errors.name" class="text-xs text-rose-600">{{ errors.name[0] }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Tipe</span>
                            <select v-model="form.tipe" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="t in opsi.tipe" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Role</span>
                            <select v-model="form.role" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="r in opsi.role" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Cabang</span>
                            <select v-model="form.cabang_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">— pilih —</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                            <span v-if="errors.cabang_id" class="text-xs text-rose-600">{{ errors.cabang_id[0] }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Unit Kerja</span>
                            <select v-model="form.uker_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">— pilih —</option>
                                <option v-for="u in ukerOpsi" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                            <span v-if="errors.uker_id" class="text-xs text-rose-600">{{ errors.uker_id[0] }}</span>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-xs font-medium text-gray-500">
                                Password
                                <em v-if="sedangEdit" class="font-normal text-gray-400">
                                    — kosongkan bila tidak ingin mengubah
                                </em>
                            </span>
                            <input
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            />
                            <span v-if="errors.password" class="text-xs text-rose-600">{{ errors.password[0] }}</span>
                        </label>
                    </div>

                    <div class="mt-4 flex gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="menyimpan"
                            @click="simpan"
                        >
                            {{ menyimpan ? 'Menyimpan…' : 'Simpan' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200"
                            @click="formTampil = false"
                        >
                            Batal
                        </button>
                    </div>
                </div>

                <!-- Tabel -->
                <div class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat" />

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        v-for="k in KOLOM"
                                        :key="k.key"
                                        scope="col"
                                        class="cursor-pointer select-none px-4 py-2 text-left text-xs font-semibold text-gray-500"
                                        @click="sort.urutkanKolom(k.key)"
                                    >
                                        {{ k.label }}
                                        <SortArrow :arah="sort.arahUntuk(k.key)" />
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Status</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="u in usersTerurut" :key="u.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 font-medium text-gray-800">{{ u.username }}</td>
                                    <td class="px-4 py-2 text-gray-700">{{ u.name }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ u.tipe }}</td>
                                    <td class="px-4 py-2">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                u.role === 'admin'
                                                    ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                                                    : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200'
                                            "
                                        >
                                            {{ u.role }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">{{ u.cabang ?? '–' }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ u.uker ?? '–' }}</td>
                                    <td class="px-4 py-2">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                u.is_locked
                                                    ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                                    : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                                            "
                                        >
                                            {{ u.is_locked ? 'Terkunci' : 'Aktif' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                        <button type="button" class="text-indigo-600 hover:underline" @click="bukaEdit(u)">
                                            Edit
                                        </button>
                                        <button
                                            v-if="u.id !== akuSendiri"
                                            type="button"
                                            class="ms-3 hover:underline"
                                            :class="u.is_locked ? 'text-emerald-600' : 'text-amber-600'"
                                            @click="kunci(u)"
                                        >
                                            {{ u.is_locked ? 'Buka' : 'Kunci' }}
                                        </button>
                                        <button
                                            v-if="u.id !== akuSendiri"
                                            type="button"
                                            class="ms-3 text-rose-600 hover:underline"
                                            @click="hapus(u)"
                                        >
                                            Hapus
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!usersTerurut.length">
                                    <td colspan="9" class="px-4 py-6 text-center text-gray-400">
                                        Tidak ada user yang cocok.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
