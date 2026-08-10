<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchUkerPerCabang,
    fetchUsers,
    hapusUser,
    perbaruiUser,
    simpanUser,
    toggleKunci,
    uploadUsers,
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
const berkasImport = ref(null);
const inputImport = ref(null);
const mengimpor = ref(false);

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

const akunAktif = computed(() => Math.max(0, Number(statistik.value.total ?? 0) - Number(statistik.value.terkunci ?? 0)));

function inisial(nama) {
    return String(nama ?? '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((bagian) => bagian.charAt(0).toUpperCase())
        .join('') || '?';
}

async function resetFilter() {
    Object.assign(filter, { cari: '', cabang_id: null, tipe: null });
    await muat();
}

async function imporUser() {
    if (!berkasImport.value) {
        lapor('Pilih file CSV atau Excel terlebih dahulu.', 'gagal');
        return;
    }

    mengimpor.value = true;
    try {
        const hasil = await uploadUsers(berkasImport.value);
        lapor(hasil.message);
        berkasImport.value = null;
        if (inputImport.value) inputImport.value.value = '';
        await muat();
    } catch (e) {
        lapor(
            e?.response?.data?.message
                ?? e?.response?.data?.errors?.berkas?.[0]
                ?? 'Import user gagal.',
            'gagal',
        );
    } finally {
        mengimpor.value = false;
    }
}

onMounted(muat);
</script>

<template>
    <Head title="Manajemen User" />

    <AuthenticatedLayout>
        <template #header>
            <div class="user-header">
                <div class="user-header__identity">
                    <span class="user-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                            <circle cx="8.5" cy="7" r="4" />
                            <path d="M20 8v6M23 11h-6" />
                        </svg>
                    </span>
                    <div>
                        <p class="user-header__eyebrow">ADMINISTRASI SISTEM</p>
                        <h2 class="user-header__title">Manajemen User</h2>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Link
                        :href="route('admin.index')"
                        class="inline-flex min-h-[2.65rem] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-xs font-semibold text-slate-600 shadow-sm transition hover:border-blue-200 hover:text-blue-700"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                        Kembali ke Admin
                    </Link>
                    <button class="user-header__add" type="button" @click="bukaBaru">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        Tambah User
                    </button>
                </div>
            </div>
        </template>

        <div class="user-page">
            <div class="user-container">
                <div
                    v-if="pesan"
                    class="user-alert"
                    :class="pesan.jenis === 'sukses' ? 'user-alert--success' : 'user-alert--danger'"
                >
                    <span class="user-alert__icon" aria-hidden="true">
                        <svg v-if="pesan.jenis === 'sukses'" viewBox="0 0 24 24" fill="none"><path d="M20 6 9 17l-5-5" /></svg>
                        <svg v-else viewBox="0 0 24 24" fill="none"><path d="M12 8v5M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
                    </span>
                    <span>{{ pesan.teks }}</span>
                    <button type="button" aria-label="Tutup notifikasi" @click="pesan = null">×</button>
                </div>

                <section class="user-hero">
                    <div class="user-hero__content">
                        <span class="user-hero__badge">KONTROL AKSES</span>
                        <h1>Kelola akun dan hak akses pengguna</h1>
                        <p>Tambahkan pengguna, atur unit kerja, dan amankan akses dashboard dalam satu halaman.</p>
                        <div class="user-hero__types">
                            <span v-for="(jumlah, tipe) in statistik.per_tipe" :key="tipe">
                                {{ tipe }} <strong>{{ jumlah }}</strong>
                            </span>
                        </div>
                    </div>
                    <div class="user-hero__art" aria-hidden="true">
                        <span class="user-hero__avatar user-hero__avatar--one">U</span>
                        <span class="user-hero__avatar user-hero__avatar--two">A</span>
                        <span class="user-hero__shield">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v5c0 5.2 3.4 8.7 8 10 4.6-1.3 8-4.8 8-10V6l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>
                        </span>
                    </div>
                </section>

                <section class="user-summary">
                    <article class="user-stat user-stat--total">
                        <span class="user-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="8.5" cy="7" r="4" /><path d="M20 8v6M23 11h-6" /></svg>
                        </span>
                        <div>
                            <p>Total user</p>
                            <strong>{{ statistik.total }}</strong>
                            <small>Akun terdaftar</small>
                        </div>
                    </article>
                    <article class="user-stat user-stat--admin">
                        <span class="user-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v5c0 5.2 3.4 8.7 8 10 4.6-1.3 8-4.8 8-10V6l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>
                        </span>
                        <div>
                            <p>Administrator</p>
                            <strong>{{ statistik.admin }}</strong>
                            <small>Akses penuh</small>
                        </div>
                    </article>
                    <article class="user-stat user-stat--active">
                        <span class="user-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" /><path d="m8 12 2.5 2.5L16 9" /></svg>
                        </span>
                        <div>
                            <p>Akun aktif</p>
                            <strong>{{ akunAktif }}</strong>
                            <small>Siap digunakan</small>
                        </div>
                    </article>
                    <article class="user-stat user-stat--locked">
                        <span class="user-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
                        </span>
                        <div>
                            <p>Akun terkunci</p>
                            <strong>{{ statistik.terkunci }}</strong>
                            <small>Perlu ditinjau</small>
                        </div>
                    </article>
                </section>

                <section class="relative mt-4 overflow-hidden rounded-2xl bg-gradient-to-r from-[#0758bd] via-[#0d68d2] to-[#2b86e8] p-5 text-white shadow-lg shadow-blue-900/10 sm:p-6">
                    <div class="pointer-events-none absolute -right-16 -top-20 h-52 w-52 rounded-full bg-white/10" />
                    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-100">Import Manajemen User</p>
                            <h3 class="mt-1 text-lg font-extrabold">Tambah atau perbarui user secara massal</h3>
                            <p class="mt-1 max-w-2xl text-xs leading-5 text-blue-100">
                                Format: id_region, id_cabang, id_uker, User, Nama, Type Uker, Role, Password. Password akun lama tidak ditimpa.
                            </p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <a
                                :href="route('admin.users.template')"
                                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 text-xs font-bold text-white transition hover:bg-white/20"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" /></svg>
                                Unduh Template
                            </a>
                            <input
                                ref="inputImport"
                                type="file"
                                accept=".csv,.txt,.xlsx,.xls"
                                class="max-w-full rounded-xl bg-white/10 text-xs text-white file:mr-3 file:rounded-xl file:border-0 file:bg-white file:px-4 file:py-2.5 file:text-xs file:font-bold file:text-blue-700"
                                @change="berkasImport = $event.target.files?.[0] ?? null"
                            />
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white px-5 text-xs font-extrabold text-blue-700 shadow-lg transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!berkasImport || mengimpor"
                                @click="imporUser"
                            >
                                {{ mengimpor ? 'Mengimpor…' : 'Import User' }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="user-filter-panel">
                    <div class="user-filter-panel__heading">
                        <div>
                            <h3>Daftar pengguna</h3>
                            <p>Cari dan saring pengguna berdasarkan cabang atau tipe akses.</p>
                        </div>
                        <span>{{ users.length }} hasil</span>
                    </div>

                    <div class="user-filter-grid">
                        <label class="user-field user-field--search">
                            <span>Cari pengguna</span>
                            <div class="user-input-icon">
                                <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                                <input
                                    v-model="filter.cari"
                                    type="search"
                                    placeholder="Username atau nama"
                                    @keyup.enter="muat"
                                />
                            </div>
                        </label>
                        <label class="user-field">
                            <span>Cabang</span>
                            <select v-model="filter.cabang_id">
                                <option :value="null">Semua cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
                        <label class="user-field">
                            <span>Tipe akses</span>
                            <select v-model="filter.tipe">
                                <option :value="null">Semua tipe</option>
                                <option v-for="t in opsi.tipe" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>
                        <div class="user-filter-actions">
                            <button class="user-button user-button--ghost" type="button" @click="resetFilter">
                                <svg viewBox="0 0 24 24" fill="none"><path d="M4 4v6h6M20 20v-6h-6" /><path d="M5.5 15a8 8 0 0 0 13-6M18.5 9a8 8 0 0 0-13 6" /></svg>
                                Reset
                            </button>
                            <button class="user-button user-button--primary" type="button" @click="muat">
                                <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                                Terapkan
                            </button>
                        </div>
                    </div>
                </section>

                <section v-if="formTampil" class="user-form-panel">
                    <header class="user-form-panel__header">
                        <div class="user-form-panel__identity">
                            <span>
                                <svg viewBox="0 0 24 24" fill="none"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" /></svg>
                            </span>
                            <div>
                                <p>{{ sedangEdit ? 'PERBARUI AKUN' : 'AKUN BARU' }}</p>
                                <h3>{{ sedangEdit ? `Edit ${form.username}` : 'Tambah User Baru' }}</h3>
                            </div>
                        </div>
                        <button class="user-form-panel__close" type="button" aria-label="Tutup form" @click="formTampil = false">×</button>
                    </header>

                    <div class="user-form-grid">
                        <label class="user-field">
                            <span>Username</span>
                            <input v-model="form.username" type="text" placeholder="Masukkan username" />
                            <small v-if="errors.username">{{ errors.username[0] }}</small>
                        </label>
                        <label class="user-field">
                            <span>Nama lengkap</span>
                            <input v-model="form.name" type="text" placeholder="Masukkan nama lengkap" />
                            <small v-if="errors.name">{{ errors.name[0] }}</small>
                        </label>
                        <label class="user-field">
                            <span>Tipe</span>
                            <select v-model="form.tipe">
                                <option v-for="t in opsi.tipe" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>
                        <label class="user-field">
                            <span>Role</span>
                            <select v-model="form.role">
                                <option v-for="r in opsi.role" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </label>
                        <label class="user-field">
                            <span>Cabang</span>
                            <select v-model="form.cabang_id">
                                <option :value="null">Pilih cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                            <small v-if="errors.cabang_id">{{ errors.cabang_id[0] }}</small>
                        </label>
                        <label class="user-field">
                            <span>Unit kerja</span>
                            <select v-model="form.uker_id">
                                <option :value="null">Pilih unit kerja</option>
                                <option v-for="u in ukerOpsi" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                            <small v-if="errors.uker_id">{{ errors.uker_id[0] }}</small>
                        </label>
                        <label class="user-field user-field--password">
                            <span>
                                Password
                                <em v-if="sedangEdit">Kosongkan jika tidak diubah</em>
                            </span>
                            <input v-model="form.password" type="password" autocomplete="new-password" placeholder="Masukkan password" />
                            <small v-if="errors.password">{{ errors.password[0] }}</small>
                        </label>
                    </div>

                    <footer class="user-form-panel__footer">
                        <button class="user-button user-button--ghost" type="button" @click="formTampil = false">Batal</button>
                        <button class="user-button user-button--primary" type="button" :disabled="menyimpan" @click="simpan">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" /><path d="M17 21v-8H7v8M7 3v5h8" /></svg>
                            {{ menyimpan ? 'Menyimpan…' : 'Simpan User' }}
                        </button>
                    </footer>
                </section>

                <section class="user-table-panel">
                    <LoadingOverlay :show="memuat" />
                    <header class="user-table-panel__header">
                        <div>
                            <h3>Data pengguna</h3>
                            <p>Kelola identitas, akses, status, dan penempatan pengguna.</p>
                        </div>
                        <button class="user-button user-button--soft" type="button" @click="bukaBaru">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" /></svg>
                            Tambah user
                        </button>
                    </header>

                    <div class="user-table-wrap">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th
                                        v-for="k in KOLOM"
                                        :key="k.key"
                                        scope="col"
                                        @click="sort.urutkanKolom(k.key)"
                                    >
                                        {{ k.label }} <SortArrow :arah="sort.arahUntuk(k.key)" />
                                    </th>
                                    <th>Status</th>
                                    <th class="user-table__right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in usersTerurut" :key="u.id">
                                    <td>
                                        <div class="user-person">
                                            <span class="user-avatar" :class="u.role === 'admin' ? 'user-avatar--admin' : ''">{{ inisial(u.name) }}</span>
                                            <div>
                                                <strong>@{{ u.username }}</strong>
                                                <small>ID {{ u.id }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="user-name">{{ u.name }}</span></td>
                                    <td><span class="user-type">{{ u.tipe }}</span></td>
                                    <td>
                                        <span class="user-role" :class="u.role === 'admin' ? 'user-role--admin' : 'user-role--user'">
                                            <span />{{ u.role }}
                                        </span>
                                    </td>
                                    <td><span class="user-office">{{ u.cabang ?? '–' }}</span></td>
                                    <td><span class="user-office">{{ u.uker ?? '–' }}</span></td>
                                    <td>
                                        <span class="user-status" :class="u.is_locked ? 'user-status--locked' : 'user-status--active'">
                                            <span />{{ u.is_locked ? 'Terkunci' : 'Aktif' }}
                                        </span>
                                    </td>
                                    <td class="user-table__right">
                                        <div class="user-row-actions">
                                            <button class="user-action user-action--edit" type="button" title="Edit user" @click="bukaEdit(u)">
                                                <svg viewBox="0 0 24 24" fill="none"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" /></svg>
                                                Edit
                                            </button>
                                            <button
                                                v-if="u.id !== akuSendiri"
                                                class="user-action"
                                                :class="u.is_locked ? 'user-action--unlock' : 'user-action--lock'"
                                                type="button"
                                                :title="u.is_locked ? 'Buka akun' : 'Kunci akun'"
                                                @click="kunci(u)"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" /><path :d="u.is_locked ? 'M8 10V7a4 4 0 0 1 7.5-2' : 'M8 10V7a4 4 0 0 1 8 0v3'" /></svg>
                                                {{ u.is_locked ? 'Buka' : 'Kunci' }}
                                            </button>
                                            <button
                                                v-if="u.id !== akuSendiri"
                                                class="user-action user-action--delete"
                                                type="button"
                                                title="Hapus user"
                                                @click="hapus(u)"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v5M14 11v5" /></svg>
                                                Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!usersTerurut.length">
                                    <td colspan="9">
                                        <div class="user-empty">
                                            <span>
                                                <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                                            </span>
                                            <strong>Tidak ada user yang cocok</strong>
                                            <p>Ubah kata pencarian atau reset filter untuk menampilkan data.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.user-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.user-header__identity {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.user-header__icon {
    display: grid;
    height: 2.75rem;
    width: 2.75rem;
    place-items: center;
    border-radius: 0.9rem;
    background: linear-gradient(145deg, #0b67d5, #0754b9);
    color: white;
    box-shadow: 0 8px 18px rgba(8, 91, 197, 0.25);
}

.user-header__icon svg,
.user-header__add svg,
.user-stat__icon svg,
.user-button svg,
.user-form-panel__identity svg,
.user-action svg,
.user-input-icon svg,
.user-alert__icon svg,
.user-empty svg {
    height: 1.15rem;
    width: 1.15rem;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.user-header__eyebrow {
    margin: 0;
    color: #2563eb;
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.15em;
}

.user-header__title {
    margin: 0.15rem 0 0;
    color: #172033;
    font-size: 1.2rem;
    font-weight: 800;
}

.user-header__add {
    display: inline-flex;
    min-height: 2.65rem;
    align-items: center;
    gap: 0.5rem;
    border: 0;
    border-radius: 0.8rem;
    background: linear-gradient(135deg, #0b67d5, #0754b9);
    padding: 0 1rem;
    color: white;
    font-size: 0.82rem;
    font-weight: 750;
    box-shadow: 0 8px 18px rgba(8, 91, 197, 0.22);
    transition: all 0.2s ease;
}

.user-header__add:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 22px rgba(8, 91, 197, 0.28);
}

.user-page {
    min-height: calc(100vh - 5rem);
    background: linear-gradient(180deg, #f3f7fc 0%, #edf3f9 100%);
    padding: 1.5rem 1rem 3rem;
}

.user-container {
    margin: 0 auto;
    max-width: 1280px;
}

.user-alert {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 1rem;
    border-radius: 0.9rem;
    padding: 0.8rem 1rem;
    font-size: 0.82rem;
    font-weight: 650;
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
}

.user-alert--success {
    border: 1px solid #a7f3d0;
    background: #ecfdf5;
    color: #047857;
}

.user-alert--danger {
    border: 1px solid #fecdd3;
    background: #fff1f2;
    color: #be123c;
}

.user-alert__icon {
    display: grid;
    height: 1.8rem;
    width: 1.8rem;
    flex: none;
    place-items: center;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.72);
}

.user-alert > button {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: currentColor;
    font-size: 1.25rem;
    line-height: 1;
    opacity: 0.65;
}

.user-hero {
    position: relative;
    display: flex;
    min-height: 170px;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;
    overflow: hidden;
    border-radius: 1.35rem;
    background: linear-gradient(115deg, #0754b9 0%, #0a66d2 55%, #2f8de5 100%);
    padding: 1.7rem 1.85rem;
    color: white;
    box-shadow: 0 16px 34px rgba(7, 82, 181, 0.2);
}

.user-hero::before,
.user-hero::after {
    content: '';
    position: absolute;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.08);
}

.user-hero::before {
    right: -70px;
    top: -140px;
    height: 310px;
    width: 310px;
}

.user-hero::after {
    right: 190px;
    bottom: -160px;
    height: 250px;
    width: 250px;
}

.user-hero__content,
.user-hero__art {
    position: relative;
    z-index: 1;
}

.user-hero__content {
    max-width: 720px;
}

.user-hero__badge {
    display: inline-flex;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.14);
    padding: 0.35rem 0.65rem;
    color: #dbeafe;
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.15em;
}

.user-hero h1 {
    margin: 0.75rem 0 0;
    font-size: clamp(1.45rem, 2.5vw, 2rem);
    font-weight: 850;
    letter-spacing: -0.025em;
}

.user-hero p {
    margin: 0.55rem 0 0;
    color: #dbeafe;
    font-size: 0.9rem;
    line-height: 1.65;
}

.user-hero__types {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.user-hero__types span {
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.32rem 0.6rem;
    color: #eaf3ff;
    font-size: 0.7rem;
    font-weight: 650;
}

.user-hero__types strong {
    margin-left: 0.25rem;
    color: white;
}

.user-hero__art {
    min-width: 235px;
    height: 120px;
}

.user-hero__avatar,
.user-hero__shield {
    position: absolute;
    display: grid;
    place-items: center;
    border: 3px solid rgba(255, 255, 255, 0.75);
    border-radius: 9999px;
    color: white;
    font-weight: 800;
    box-shadow: 0 12px 28px rgba(3, 39, 93, 0.25);
}

.user-hero__avatar--one {
    left: 5px;
    top: 45px;
    height: 58px;
    width: 58px;
    background: linear-gradient(145deg, #38bdf8, #0284c7);
}

.user-hero__avatar--two {
    right: 10px;
    top: 48px;
    height: 54px;
    width: 54px;
    background: linear-gradient(145deg, #a78bfa, #7c3aed);
}

.user-hero__shield {
    left: 76px;
    top: 7px;
    height: 92px;
    width: 92px;
    background: linear-gradient(145deg, #ffffff, #e0ecff);
    color: #0b67d5;
}

.user-hero__shield svg {
    height: 2.5rem;
    width: 2.5rem;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.user-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.user-stat {
    display: flex;
    min-height: 112px;
    align-items: center;
    gap: 0.9rem;
    border: 1px solid #e2e8f0;
    border-radius: 1.05rem;
    background: white;
    padding: 1rem;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.user-stat__icon {
    display: grid;
    height: 2.9rem;
    width: 2.9rem;
    flex: none;
    place-items: center;
    border-radius: 0.9rem;
}

.user-stat--total .user-stat__icon {
    background: #eaf2ff;
    color: #1d4ed8;
}

.user-stat--admin .user-stat__icon {
    background: #f1edff;
    color: #7c3aed;
}

.user-stat--active .user-stat__icon {
    background: #e9fbf3;
    color: #059669;
}

.user-stat--locked .user-stat__icon {
    background: #fff1f2;
    color: #e11d48;
}

.user-stat p,
.user-stat small,
.user-stat strong {
    display: block;
    margin: 0;
}

.user-stat p {
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.user-stat strong {
    margin-top: 0.2rem;
    color: #172033;
    font-size: 1.55rem;
    line-height: 1;
}

.user-stat small {
    margin-top: 0.35rem;
    color: #94a3b8;
    font-size: 0.7rem;
}

.user-filter-panel,
.user-form-panel,
.user-table-panel {
    position: relative;
    margin-top: 1rem;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 1.05rem;
    background: white;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
}

.user-filter-panel {
    padding: 1.15rem;
}

.user-filter-panel__heading,
.user-table-panel__header,
.user-form-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.user-filter-panel__heading h3,
.user-table-panel__header h3,
.user-form-panel__header h3 {
    margin: 0;
    color: #172033;
    font-size: 0.95rem;
    font-weight: 800;
}

.user-filter-panel__heading p,
.user-table-panel__header p {
    margin: 0.25rem 0 0;
    color: #94a3b8;
    font-size: 0.72rem;
}

.user-filter-panel__heading > span {
    border-radius: 9999px;
    background: #eff6ff;
    padding: 0.35rem 0.65rem;
    color: #1d4ed8;
    font-size: 0.7rem;
    font-weight: 750;
}

.user-filter-grid {
    display: grid;
    grid-template-columns: minmax(250px, 1.35fr) minmax(190px, 1fr) minmax(150px, 0.75fr) auto;
    gap: 0.8rem;
    align-items: end;
    margin-top: 1rem;
}

.user-field {
    display: block;
}

.user-field > span {
    display: flex;
    min-height: 1.1rem;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.4rem;
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.user-field em {
    color: #94a3b8;
    font-size: 0.62rem;
    font-style: normal;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}

.user-field input,
.user-field select {
    height: 2.75rem;
    width: 100%;
    border: 1px solid #d8e1ec;
    border-radius: 0.78rem;
    background: white;
    padding: 0 0.85rem;
    color: #334155;
    font-size: 0.78rem;
    font-weight: 600;
    outline: none;
    transition: all 0.2s ease;
}

.user-field input::placeholder {
    color: #a6b2c2;
    font-weight: 500;
}

.user-field input:focus,
.user-field select:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.user-field small {
    display: block;
    margin-top: 0.3rem;
    color: #e11d48;
    font-size: 0.68rem;
    font-weight: 650;
}

.user-input-icon {
    position: relative;
}

.user-input-icon svg {
    position: absolute;
    left: 0.82rem;
    top: 50%;
    color: #94a3b8;
    transform: translateY(-50%);
}

.user-input-icon input {
    padding-left: 2.5rem;
}

.user-filter-actions,
.user-form-panel__footer,
.user-row-actions {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.user-button {
    display: inline-flex;
    height: 2.75rem;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    border-radius: 0.78rem;
    padding: 0 0.95rem;
    font-size: 0.75rem;
    font-weight: 750;
    transition: all 0.2s ease;
}

.user-button:disabled {
    cursor: wait;
    opacity: 0.6;
}

.user-button--primary {
    border: 1px solid #0755bd;
    background: linear-gradient(135deg, #0b67d5, #0754b9);
    color: white;
    box-shadow: 0 6px 14px rgba(8, 91, 197, 0.18);
}

.user-button--primary:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(8, 91, 197, 0.25);
}

.user-button--ghost {
    border: 1px solid #d8e1ec;
    background: white;
    color: #64748b;
}

.user-button--ghost:hover {
    border-color: #b8c7da;
    background: #f8fafc;
    color: #334155;
}

.user-button--soft {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
}

.user-button--soft:hover {
    background: #dbeafe;
}

.user-form-panel {
    border-color: #bfdbfe;
}

.user-form-panel__header {
    border-bottom: 1px solid #e7eef7;
    background: linear-gradient(90deg, #f5f9ff, #ffffff);
    padding: 1rem 1.15rem;
}

.user-form-panel__identity {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-form-panel__identity > span {
    display: grid;
    height: 2.5rem;
    width: 2.5rem;
    place-items: center;
    border-radius: 0.8rem;
    background: #eaf2ff;
    color: #1d4ed8;
}

.user-form-panel__identity p {
    margin: 0;
    color: #3b82f6;
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.user-form-panel__identity h3 {
    margin-top: 0.15rem;
}

.user-form-panel__close {
    display: grid;
    height: 2rem;
    width: 2rem;
    place-items: center;
    border: 1px solid #e2e8f0;
    border-radius: 9999px;
    background: white;
    color: #64748b;
    font-size: 1.1rem;
}

.user-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.9rem;
    padding: 1.15rem;
}

.user-field--password {
    grid-column: span 2;
}

.user-form-panel__footer {
    justify-content: flex-end;
    border-top: 1px solid #eef2f7;
    background: #fbfdff;
    padding: 0.9rem 1.15rem;
}

.user-table-panel__header {
    border-bottom: 1px solid #e7edf5;
    padding: 1rem 1.15rem;
}

.user-table-wrap {
    overflow-x: auto;
}

.user-table {
    min-width: 1120px;
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}

.user-table thead {
    background: #f7f9fc;
}

.user-table th {
    cursor: pointer;
    border-bottom: 1px solid #e5ebf3;
    padding: 0.72rem 0.9rem;
    color: #64748b;
    font-size: 0.64rem;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.user-table td {
    border-bottom: 1px solid #eef2f7;
    padding: 0.72rem 0.9rem;
    color: #475569;
    vertical-align: middle;
}

.user-table tbody tr {
    transition: background 0.18s ease;
}

.user-table tbody tr:hover {
    background: #f8fbff;
}

.user-table tbody tr:last-child td {
    border-bottom: 0;
}

.user-table__right {
    text-align: right !important;
}

.user-person {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.user-avatar {
    display: grid;
    height: 2.25rem;
    width: 2.25rem;
    flex: none;
    place-items: center;
    border-radius: 0.75rem;
    background: linear-gradient(145deg, #e8f1ff, #dbeafe);
    color: #1d4ed8;
    font-size: 0.68rem;
    font-weight: 850;
}

.user-avatar--admin {
    background: linear-gradient(145deg, #ede9fe, #ddd6fe);
    color: #7c3aed;
}

.user-person strong,
.user-person small {
    display: block;
}

.user-person strong {
    color: #1e293b;
    font-size: 0.76rem;
}

.user-person small {
    margin-top: 0.1rem;
    color: #94a3b8;
    font-size: 0.62rem;
}

.user-name {
    color: #334155;
    font-weight: 700;
}

.user-type {
    display: inline-flex;
    border-radius: 0.45rem;
    background: #f1f5f9;
    padding: 0.25rem 0.48rem;
    color: #475569;
    font-size: 0.66rem;
    font-weight: 750;
}

.user-role,
.user-status {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border-radius: 9999px;
    padding: 0.28rem 0.55rem;
    font-size: 0.65rem;
    font-weight: 750;
    text-transform: capitalize;
}

.user-role > span,
.user-status > span {
    height: 0.38rem;
    width: 0.38rem;
    border-radius: 9999px;
    background: currentColor;
}

.user-role--admin {
    background: #f2efff;
    color: #7c3aed;
}

.user-role--user {
    background: #f1f5f9;
    color: #64748b;
}

.user-status--active {
    background: #eafaf2;
    color: #059669;
}

.user-status--locked {
    background: #fff1f2;
    color: #e11d48;
}

.user-office {
    color: #64748b;
    font-size: 0.71rem;
    font-weight: 550;
}

.user-row-actions {
    justify-content: flex-end;
}

.user-action {
    display: inline-flex;
    height: 2rem;
    align-items: center;
    gap: 0.3rem;
    border: 1px solid transparent;
    border-radius: 0.55rem;
    background: transparent;
    padding: 0 0.5rem;
    font-size: 0.65rem;
    font-weight: 750;
    transition: all 0.18s ease;
}

.user-action svg {
    height: 0.9rem;
    width: 0.9rem;
}

.user-action--edit {
    color: #1d4ed8;
}

.user-action--edit:hover {
    border-color: #bfdbfe;
    background: #eff6ff;
}

.user-action--lock {
    color: #d97706;
}

.user-action--lock:hover {
    border-color: #fde68a;
    background: #fffbeb;
}

.user-action--unlock {
    color: #059669;
}

.user-action--unlock:hover {
    border-color: #a7f3d0;
    background: #ecfdf5;
}

.user-action--delete {
    color: #e11d48;
}

.user-action--delete:hover {
    border-color: #fecdd3;
    background: #fff1f2;
}

.user-empty {
    display: flex;
    min-height: 190px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    text-align: center;
}

.user-empty > span {
    display: grid;
    height: 3rem;
    width: 3rem;
    place-items: center;
    border-radius: 9999px;
    background: #f1f5f9;
    color: #64748b;
}

.user-empty strong {
    margin-top: 0.75rem;
    color: #475569;
    font-size: 0.82rem;
}

.user-empty p {
    margin: 0.25rem 0 0;
    font-size: 0.7rem;
}

@media (max-width: 1024px) {
    .user-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .user-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .user-filter-actions {
        justify-content: flex-end;
    }

    .user-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .user-header__add {
        padding: 0 0.75rem;
    }

    .user-hero {
        min-height: 0;
        padding: 1.35rem;
    }

    .user-hero__art {
        display: none;
    }

    .user-summary,
    .user-filter-grid,
    .user-form-grid {
        grid-template-columns: 1fr;
    }

    .user-field--password {
        grid-column: auto;
    }

    .user-filter-actions,
    .user-form-panel__footer {
        justify-content: stretch;
    }

    .user-filter-actions .user-button,
    .user-form-panel__footer .user-button {
        flex: 1;
    }
}
</style>
