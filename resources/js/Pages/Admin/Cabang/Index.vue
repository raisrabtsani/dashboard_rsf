<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    areas: { type: Array, default: () => [] },
    cabang: { type: Array, default: () => [] },
    tipeUker: { type: Array, default: () => ['BO', 'SBO', 'UNIT', 'KK'] },
    statistik: {
        type: Object,
        default: () => ({ total_area: 0, total_cabang: 0, total_uker: 0 }),
    },
});

const cari = ref('');
const areaId = ref('');
const terbuka = ref([]);
const pesan = ref(null);
const sedangAksi = ref(false);
const pilihan = ref([]);

const berkas = ref(null);
const inputBerkas = ref(null);
const mengunggah = ref(false);

const modal = reactive({
    tampil: false,
    jenis: null,
    id: null,
    nama: '',
    area_id: null,
    cabang_id: null,
    tipe: 'UNIT',
});
const errors = ref({});
const menyimpan = ref(false);

const hasil = computed(() => {
    const kata = cari.value.trim().toLowerCase();

    return props.cabang.filter((item) => {
        const cocokArea = areaId.value === '' || Number(item.area_id) === Number(areaId.value);
        const cocokKata = kata === ''
            || item.nama.toLowerCase().includes(kata)
            || String(item.id).includes(kata)
            || item.area.toLowerCase().includes(kata)
            || item.uker.some((uker) =>
                uker.nama.toLowerCase().includes(kata)
                || String(uker.id).includes(kata)
                || String(uker.tipe).toLowerCase().includes(kata),
            );

        return cocokArea && cocokKata;
    });
});

const semuaTerbuka = computed(() =>
    hasil.value.length > 0 && hasil.value.every((item) => terbuka.value.includes(item.id)),
);

const kunciPilihan = (jenis, id) => `${jenis}:${Number(id)}`;
const semuaKunciHasil = computed(() =>
    hasil.value.flatMap((item) => [
        kunciPilihan('cabang', item.id),
        ...item.uker.map((uker) => kunciPilihan('uker', uker.id)),
    ]),
);
const jumlahPilihan = computed(() => pilihan.value.length);
const jumlahCabangDipilih = computed(() =>
    pilihan.value.filter((item) => item.startsWith('cabang:')).length,
);
const jumlahUkerDipilih = computed(() =>
    pilihan.value.filter((item) => item.startsWith('uker:')).length,
);
const semuaHasilDipilih = computed(() =>
    semuaKunciHasil.value.length > 0
    && semuaKunciHasil.value.every((item) => pilihan.value.includes(item)),
);

const judulModal = computed(() =>
    modal.jenis === 'cabang' ? 'Edit Kantor Cabang' : 'Edit Unit Kerja',
);

function lapor(teks, jenis = 'sukses', detail = []) {
    pesan.value = { teks, jenis, detail };
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function muatUlang() {
    router.reload({
        only: ['areas', 'cabang', 'tipeUker', 'statistik'],
        preserveScroll: true,
    });
}

function toggle(id) {
    terbuka.value = terbuka.value.includes(id)
        ? terbuka.value.filter((item) => item !== id)
        : [...terbuka.value, id];
}

function toggleSemua() {
    if (semuaTerbuka.value) {
        const idHasil = new Set(hasil.value.map((item) => item.id));
        terbuka.value = terbuka.value.filter((id) => !idHasil.has(id));
        return;
    }

    terbuka.value = Array.from(new Set([
        ...terbuka.value,
        ...hasil.value.map((item) => item.id),
    ]));
}

function resetFilter() {
    cari.value = '';
    areaId.value = '';
}

function dipilih(jenis, id) {
    return pilihan.value.includes(kunciPilihan(jenis, id));
}

function togglePilihan(jenis, id) {
    const kunci = kunciPilihan(jenis, id);
    pilihan.value = pilihan.value.includes(kunci)
        ? pilihan.value.filter((item) => item !== kunci)
        : [...pilihan.value, kunci];
}

function kunciCabang(item) {
    return [
        kunciPilihan('cabang', item.id),
        ...item.uker.map((uker) => kunciPilihan('uker', uker.id)),
    ];
}

function cabangSemuaDipilih(item) {
    return kunciCabang(item).every((kunci) => pilihan.value.includes(kunci));
}

function cabangSebagianDipilih(item) {
    const daftar = kunciCabang(item);
    const jumlah = daftar.filter((kunci) => pilihan.value.includes(kunci)).length;
    return jumlah > 0 && jumlah < daftar.length;
}

function togglePilihanCabang(item) {
    const daftar = kunciCabang(item);

    if (daftar.every((kunci) => pilihan.value.includes(kunci))) {
        const setHapus = new Set(daftar);
        pilihan.value = pilihan.value.filter((kunci) => !setHapus.has(kunci));
        return;
    }

    pilihan.value = Array.from(new Set([...pilihan.value, ...daftar]));
}

function toggleSemuaPilihan() {
    if (semuaHasilDipilih.value) {
        const setHapus = new Set(semuaKunciHasil.value);
        pilihan.value = pilihan.value.filter((kunci) => !setHapus.has(kunci));
        return;
    }

    pilihan.value = Array.from(new Set([...pilihan.value, ...semuaKunciHasil.value]));
}

function kosongkanPilihan() {
    pilihan.value = [];
}

async function hapusPilihan() {
    if (jumlahPilihan.value === 0) return;

    const konfirmasi = [
        `Hapus ${jumlahPilihan.value} data terpilih?`,
        `${jumlahCabangDipilih.value} kantor cabang dan ${jumlahUkerDipilih.value} unit kerja akan diperiksa.`,
        'Data yang masih dipakai user, data aktual, atau RKA otomatis dilewati.',
    ].join('\n');

    if (!confirm(konfirmasi)) return;

    const payload = pilihan.value.map((kunci) => {
        const [jenis, id] = kunci.split(':');
        return { jenis, id: Number(id) };
    });

    sedangAksi.value = true;

    try {
        const { data } = await axios.delete(route('admin.cabang.selected'), {
            data: { pilihan: payload },
        });
        const detail = (data.gagal ?? []).map((item) => `${item.nama}: ${item.alasan}`);
        pilihan.value = [];
        lapor(data.message, detail.length ? 'peringatan' : 'sukses', detail);
        muatUlang();
    } catch (error) {
        lapor(
            error?.response?.data?.message
                ?? error?.response?.data?.errors?.pilihan?.[0]
                ?? 'Penghapusan data terpilih gagal.',
            'gagal',
        );
    } finally {
        sedangAksi.value = false;
    }
}

function pilihBerkas(event) {
    berkas.value = event.target.files?.[0] ?? null;
}

async function unggah() {
    if (!berkas.value) {
        lapor('Pilih file CSV atau Excel terlebih dahulu.', 'gagal');
        return;
    }

    const form = new FormData();
    form.append('berkas', berkas.value);
    mengunggah.value = true;

    try {
        const { data } = await axios.post(route('admin.cabang.upload'), form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        lapor(data.message);
        berkas.value = null;
        if (inputBerkas.value) inputBerkas.value.value = '';
        muatUlang();
    } catch (error) {
        lapor(
            error?.response?.data?.message
                ?? error?.response?.data?.errors?.berkas?.[0]
                ?? 'Upload master kantor gagal.',
            'gagal',
        );
    } finally {
        mengunggah.value = false;
    }
}

function bukaEditCabang(item) {
    Object.assign(modal, {
        tampil: true,
        jenis: 'cabang',
        id: item.id,
        nama: item.nama,
        area_id: item.area_id,
        cabang_id: null,
        tipe: 'UNIT',
    });
    errors.value = {};
}

function bukaEditUker(uker) {
    Object.assign(modal, {
        tampil: true,
        jenis: 'uker',
        id: uker.id,
        nama: uker.nama,
        area_id: null,
        cabang_id: uker.cabang_id,
        tipe: uker.tipe,
    });
    errors.value = {};
}

function tutupModal() {
    modal.tampil = false;
    errors.value = {};
}

async function simpanEdit() {
    menyimpan.value = true;
    errors.value = {};

    try {
        const endpoint = modal.jenis === 'cabang'
            ? route('admin.cabang.update', { cabang: modal.id })
            : route('admin.uker.update', { uker: modal.id });

        const payload = modal.jenis === 'cabang'
            ? { nama: modal.nama, area_id: modal.area_id || null }
            : { nama: modal.nama, cabang_id: modal.cabang_id, tipe: modal.tipe };

        const { data } = await axios.put(endpoint, payload);
        tutupModal();
        lapor(data.message);
        muatUlang();
    } catch (error) {
        if (error?.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        } else {
            lapor(error?.response?.data?.message ?? 'Perubahan gagal disimpan.', 'gagal');
        }
    } finally {
        menyimpan.value = false;
    }
}

async function hapusCabang(item) {
    if (!confirm(`Hapus kantor cabang ${item.nama}? Cabang hanya dapat dihapus bila tidak memiliki unit kerja dan tidak dipakai data historis.`)) return;

    sedangAksi.value = true;
    try {
        const { data } = await axios.delete(route('admin.cabang.destroy', { cabang: item.id }));
        const setHapus = new Set(kunciCabang(item));
        pilihan.value = pilihan.value.filter((kunci) => !setHapus.has(kunci));
        lapor(data.message);
        muatUlang();
    } catch (error) {
        lapor(error?.response?.data?.message ?? 'Kantor cabang gagal dihapus.', 'gagal');
    } finally {
        sedangAksi.value = false;
    }
}

async function hapusUker(uker) {
    if (!confirm(`Hapus unit kerja ${uker.nama}? Unit yang masih dipakai data historis atau user tidak dapat dihapus.`)) return;

    sedangAksi.value = true;
    try {
        const { data } = await axios.delete(route('admin.uker.destroy', { uker: uker.id }));
        pilihan.value = pilihan.value.filter((kunci) => kunci !== kunciPilihan('uker', uker.id));
        lapor(data.message);
        muatUlang();
    } catch (error) {
        lapor(error?.response?.data?.message ?? 'Unit kerja gagal dihapus.', 'gagal');
    } finally {
        sedangAksi.value = false;
    }
}

function formatWaktu(nilai) {
    if (!nilai) return '—';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(nilai));
}

const badgeTipe = (tipe) => ({
    BO: 'bg-blue-50 text-blue-700 ring-blue-100',
    SBO: 'bg-violet-50 text-violet-700 ring-violet-100',
    UNIT: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    KK: 'bg-amber-50 text-amber-700 ring-amber-100',
}[tipe] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
</script>

<template>
    <Head title="Kantor Cabang" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-800 sm:text-xl">Kantor Cabang</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Upload, edit, dan hapus master cabang serta unit kerja</p>
                </div>
                <Link
                    :href="route('admin.index')"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 shadow-sm transition hover:border-blue-200 hover:text-blue-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                    </svg>
                    Kembali ke Admin
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-[1440px] space-y-6">
            <div
                v-if="pesan"
                class="flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm shadow-sm"
                :class="pesan.jenis === 'sukses'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : pesan.jenis === 'peringatan'
                        ? 'border-amber-200 bg-amber-50 text-amber-800'
                        : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/70">
                    <svg v-if="pesan.jenis === 'sukses'" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                    <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 7a1.25 1.25 0 100 2.5A1.25 1.25 0 0010 14z" clip-rule="evenodd" /></svg>
                </span>
                <div class="flex-1 leading-6">
                    <p>{{ pesan.teks }}</p>
                    <ul v-if="pesan.detail?.length" class="mt-2 max-h-36 list-disc space-y-1 overflow-y-auto pl-5 text-xs opacity-90">
                        <li v-for="detail in pesan.detail" :key="detail">{{ detail }}</li>
                    </ul>
                </div>
                <button type="button" class="text-current/50 hover:text-current" @click="pesan = null">×</button>
            </div>

            <!-- Hero -->
            <section class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-[#0758bd] via-[#0d68d2] to-[#2b86e8] px-6 py-7 text-white shadow-lg shadow-blue-900/10 sm:px-8">
                <div class="pointer-events-none absolute -right-16 -top-24 h-72 w-72 rounded-full bg-white/10"></div>
                <div class="pointer-events-none absolute -bottom-20 right-1/3 h-48 w-48 rounded-full bg-cyan-300/10 blur-2xl"></div>

                <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-4">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/10 backdrop-blur-sm">
                            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M6 21V5l6-3 6 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-100">Master Organisasi</p>
                            <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">Kantor Cabang Region 7</h1>
                            <p class="mt-2 max-w-xl text-sm leading-6 text-blue-100">
                                Perbarui perubahan nama, tipe, atau induk unit kerja tanpa mengubah data historis dashboard.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 sm:gap-3">
                        <div class="min-w-[92px] rounded-2xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                            <p class="text-2xl font-bold">{{ statistik.total_area }}</p>
                            <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-100">Area</p>
                        </div>
                        <div class="min-w-[92px] rounded-2xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                            <p class="text-2xl font-bold">{{ statistik.total_cabang }}</p>
                            <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-100">Cabang</p>
                        </div>
                        <div class="min-w-[92px] rounded-2xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                            <p class="text-2xl font-bold">{{ statistik.total_uker }}</p>
                            <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-100">Unit Kerja</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Upload master -->
            <section class="overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm">
                <div class="grid grid-cols-1 lg:grid-cols-[1.15fr_0.85fr]">
                    <div class="p-5 sm:p-6">
                        <div class="flex items-start gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v4h14v-4" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-blue-600">Upload Master</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-800">Perbarui cabang dan unit kerja sekaligus</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-500">
                                    File di-upsert berdasarkan ID. ID baru akan ditambahkan; ID yang sudah ada akan diperbarui.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 rounded-2xl border-2 border-dashed border-blue-200 bg-blue-50/50 p-4 transition hover:border-blue-300 hover:bg-blue-50">
                            <input
                                ref="inputBerkas"
                                type="file"
                                accept=".csv,.txt,.xlsx,.xls"
                                class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-xl file:border-0 file:bg-blue-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700"
                                @change="pilihBerkas"
                            />
                            <p class="mt-2 text-xs text-slate-500">
                                Format: CSV, XLSX, atau XLS. Maksimal 20 MB.
                            </p>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="mengunggah || !berkas"
                                @click="unggah"
                            >
                                <svg v-if="mengunggah" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" stroke-opacity=".25"/><path stroke-linecap="round" d="M21 12a9 9 0 00-9-9"/></svg>
                                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v4h14v-4"/></svg>
                                {{ mengunggah ? 'Mengunggah…' : 'Upload dan Perbarui' }}
                            </button>
                            <a
                                :href="route('admin.cabang.template')"
                                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg>
                                Unduh Template
                            </a>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 bg-slate-50/80 p-5 sm:p-6 lg:border-l lg:border-t-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Kolom berkas</p>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <span class="rounded-lg bg-white px-3 py-2 font-mono text-slate-700 ring-1 ring-slate-200">id_cabang</span>
                            <span class="rounded-lg bg-white px-3 py-2 font-mono text-slate-700 ring-1 ring-slate-200">id_uker</span>
                            <span class="rounded-lg bg-white px-3 py-2 font-mono text-slate-700 ring-1 ring-slate-200">Nama Cabang</span>
                            <span class="rounded-lg bg-white px-3 py-2 font-mono text-slate-700 ring-1 ring-slate-200">Nama Uker</span>
                        </div>
                        <p class="mt-4 text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Opsional</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-700 ring-1 ring-cyan-100">id_area</span>
                            <span class="rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700 ring-1 ring-violet-100">tipe</span>
                        </div>
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-800">
                            Menghapus unit kerja hanya diperbolehkan bila ID tersebut belum dipakai data aktual, RKA, atau user.
                        </div>
                    </div>
                </div>
            </section>

            <!-- Filter -->
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_280px_auto]">
                    <label class="relative block">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                        </span>
                        <input
                            v-model="cari"
                            type="search"
                            placeholder="Cari nama, kode cabang, unit kerja, atau tipe..."
                            class="h-12 w-full rounded-xl border-slate-200 bg-slate-50 pl-11 pr-4 text-sm text-slate-700 placeholder-slate-400 focus:border-blue-500 focus:bg-white focus:ring-blue-500"
                        />
                    </label>

                    <select v-model="areaId" class="h-12 w-full rounded-xl border-slate-200 bg-slate-50 px-4 text-sm font-medium text-slate-700 focus:border-blue-500 focus:bg-white focus:ring-blue-500">
                        <option value="">Semua Area</option>
                        <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.nama }}</option>
                    </select>

                    <button type="button" class="group inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" @click="resetFilter">
                        <svg class="h-4 w-4 transition duration-300 group-hover:-rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v5h5"/></svg>
                        Reset
                    </button>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="text-xs text-slate-500">
                        Menampilkan <span class="font-bold text-slate-700">{{ hasil.length }}</span> dari
                        <span class="font-bold text-slate-700">{{ cabang.length }}</span> kantor cabang.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold transition"
                            :class="semuaHasilDipilih
                                ? 'border-blue-200 bg-blue-50 text-blue-700'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700'"
                            @click="toggleSemuaPilihan"
                        >
                            <span
                                class="flex h-4 w-4 items-center justify-center rounded border transition"
                                :class="semuaHasilDipilih ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white'"
                            >
                                <svg v-if="semuaHasilDipilih" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            </span>
                            {{ semuaHasilDipilih ? 'Batalkan Semua' : 'Pilih Semua Hasil' }}
                        </button>

                        <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-blue-50 hover:text-blue-700" @click="toggleSemua">
                            <svg class="h-4 w-4 transition" :class="semuaTerbuka ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                            {{ semuaTerbuka ? 'Tutup Semua' : 'Buka Semua' }}
                        </button>
                    </div>
                </div>

                <div v-if="jumlahPilihan > 0" class="mt-4 flex flex-col gap-3 rounded-2xl border border-rose-200 bg-gradient-to-r from-rose-50 to-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                        </span>
                        <div>
                            <p class="text-sm font-bold text-slate-800">{{ jumlahPilihan }} data dipilih</p>
                            <p class="text-xs text-slate-500">{{ jumlahCabangDipilih }} cabang dan {{ jumlahUkerDipilih }} unit kerja</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" @click="kosongkanPilihan">
                            Batal Pilih
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-rose-600/20 transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="sedangAksi"
                            @click="hapusPilihan"
                        >
                            <svg v-if="sedangAksi" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" stroke-opacity=".25"/><path stroke-linecap="round" d="M21 12a9 9 0 00-9-9"/></svg>
                            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13"/></svg>
                            Hapus Terpilih
                        </button>
                    </div>
                </div>
            </section>

            <!-- Daftar cabang -->
            <section class="space-y-3">
                <article
                    v-for="item in hasil"
                    :key="item.id"
                    class="overflow-hidden rounded-2xl border bg-white shadow-sm transition hover:shadow-md"
                    :class="cabangSemuaDipilih(item)
                        ? 'border-blue-300 ring-2 ring-blue-100'
                        : cabangSebagianDipilih(item)
                            ? 'border-amber-300 ring-2 ring-amber-100'
                            : 'border-slate-200 hover:border-blue-200'"
                >
                    <div class="flex items-center gap-3 px-4 py-4 sm:px-5">
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border transition"
                            :class="cabangSemuaDipilih(item)
                                ? 'border-blue-600 bg-blue-600 text-white shadow-sm shadow-blue-600/20'
                                : cabangSebagianDipilih(item)
                                    ? 'border-amber-400 bg-amber-400 text-white'
                                    : 'border-slate-300 bg-white text-transparent hover:border-blue-400 hover:bg-blue-50'"
                            :aria-label="`Pilih ${item.nama} beserta seluruh unit kerjanya`"
                            @click="togglePilihanCabang(item)"
                        >
                            <svg v-if="cabangSemuaDipilih(item)" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            <svg v-else-if="cabangSebagianDipilih(item)" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5 9h10v2H5z" /></svg>
                            <span v-else class="h-2 w-2 rounded-sm bg-current" />
                        </button>

                        <button type="button" class="flex min-w-0 flex-1 items-center gap-4 text-left" @click="toggle(item.id)">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M6 21V5l6-3 6 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-bold text-slate-800 sm:text-base">{{ item.nama }}</span>
                                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">ID {{ item.id }}</span>
                                </span>
                                <span class="mt-1 block text-xs text-slate-500">{{ item.area }} · diperbarui {{ formatWaktu(item.updated_at) }}</span>
                            </span>
                        </button>

                        <span class="hidden rounded-xl bg-slate-50 px-3 py-2 text-center sm:block">
                            <span class="block text-base font-bold text-slate-800">{{ item.jumlah_uker }}</span>
                            <span class="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Unit Kerja</span>
                        </span>

                        <div class="flex items-center gap-1.5">
                            <button type="button" title="Edit cabang" class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-600 transition hover:bg-blue-600 hover:text-white" @click="bukaEditCabang(item)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.7 6.3 3 3M4 20l3.8-.8L19 8a2.1 2.1 0 0 0-3-3L4.8 16.2 4 20Z"/></svg>
                            </button>
                            <button type="button" title="Hapus cabang" class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-600 transition hover:bg-rose-600 hover:text-white disabled:opacity-50" :disabled="sedangAksi" @click="hapusCabang(item)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                            </button>
                            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-50 text-slate-400" @click="toggle(item.id)">
                                <svg class="h-4 w-4 transition" :class="terbuka.includes(item.id) ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </div>
                    </div>

                    <div v-show="terbuka.includes(item.id)" class="border-t border-slate-100 bg-slate-50/70 px-4 py-4 sm:px-5">
                        <div v-if="item.uker.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                        <tr>
                                            <th class="px-4 py-3 text-left">Unit Kerja</th>
                                            <th class="px-4 py-3 text-left">Tipe</th>
                                            <th class="px-4 py-3 text-left">Terakhir Update</th>
                                            <th class="px-4 py-3 text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr
                                            v-for="uker in item.uker"
                                            :key="uker.id"
                                            class="transition"
                                            :class="dipilih('uker', uker.id) ? 'bg-blue-50/70' : 'hover:bg-blue-50/40'"
                                        >
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <button
                                                        type="button"
                                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border transition"
                                                        :class="dipilih('uker', uker.id)
                                                            ? 'border-blue-600 bg-blue-600 text-white'
                                                            : 'border-slate-300 bg-white text-transparent hover:border-blue-400 hover:bg-blue-50'"
                                                        :aria-label="`Pilih unit kerja ${uker.nama}`"
                                                        @click="togglePilihan('uker', uker.id)"
                                                    >
                                                        <svg v-if="dipilih('uker', uker.id)" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                                                        <span v-else class="h-1.5 w-1.5 rounded-sm bg-current" />
                                                    </button>
                                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V9l8-5 8 5v12M9 21v-6h6v6"/></svg>
                                                    </span>
                                                    <div>
                                                        <p class="font-bold text-slate-700">{{ uker.nama }}</p>
                                                        <p class="mt-0.5 text-[10px] text-slate-400">ID {{ uker.id }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-[9px] font-bold ring-1" :class="badgeTipe(uker.tipe)">{{ uker.tipe }}</span></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ formatWaktu(uker.updated_at) }}</td>
                                            <td class="px-4 py-3">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white" @click="bukaEditUker(uker)">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.7 6.3 3 3M4 20l3.8-.8L19 8a2.1 2.1 0 0 0-3-3L4.8 16.2 4 20Z"/></svg>
                                                        Edit
                                                    </button>
                                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-600 hover:text-white disabled:opacity-50" :disabled="sedangAksi" @click="hapusUker(uker)">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13"/></svg>
                                                        Hapus
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div v-else class="rounded-xl border border-dashed border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-400">
                            Belum ada unit kerja pada kantor cabang ini.
                        </div>
                    </div>
                </article>

                <div v-if="hasil.length === 0" class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                    </div>
                    <p class="mt-3 text-sm font-bold text-slate-700">Kantor cabang tidak ditemukan</p>
                    <p class="mt-1 text-xs text-slate-400">Ubah kata pencarian atau filter area.</p>
                </div>
            </section>
        </div>

        <!-- Modal edit -->
        <Teleport to="body">
            <div v-if="modal.tampil" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm" @click.self="tutupModal">
                <div class="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                    <div class="relative overflow-hidden bg-gradient-to-r from-blue-700 to-blue-500 px-6 py-5 text-white">
                        <div class="pointer-events-none absolute -right-8 -top-10 h-32 w-32 rounded-full bg-white/10"></div>
                        <div class="relative flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-blue-100">Master Kantor</p>
                                <h3 class="mt-1 text-xl font-bold">{{ judulModal }}</h3>
                                <p class="mt-1 text-xs text-blue-100">ID {{ modal.id }} tidak diubah agar relasi data historis tetap aman.</p>
                            </div>
                            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-xl hover:bg-white/20" @click="tutupModal">×</button>
                        </div>
                    </div>

                    <div class="space-y-4 p-6">
                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Nama</span>
                            <input v-model="modal.nama" type="text" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 focus:border-blue-500 focus:bg-white focus:ring-blue-500" />
                            <span v-if="errors.nama" class="mt-1 block text-xs text-rose-600">{{ errors.nama[0] }}</span>
                        </label>

                        <label v-if="modal.jenis === 'cabang'" class="block">
                            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Area</span>
                            <select v-model="modal.area_id" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 focus:border-blue-500 focus:bg-white focus:ring-blue-500">
                                <option :value="null">Tanpa Area</option>
                                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.nama }}</option>
                            </select>
                            <span v-if="errors.area_id" class="mt-1 block text-xs text-rose-600">{{ errors.area_id[0] }}</span>
                        </label>

                        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Induk Cabang</span>
                                <select v-model="modal.cabang_id" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 focus:border-blue-500 focus:bg-white focus:ring-blue-500">
                                    <option v-for="item in cabang" :key="item.id" :value="item.id">{{ item.nama }}</option>
                                </select>
                                <span v-if="errors.cabang_id" class="mt-1 block text-xs text-rose-600">{{ errors.cabang_id[0] }}</span>
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Tipe Unit</span>
                                <select v-model="modal.tipe" class="mt-1.5 h-12 w-full rounded-xl border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-700 focus:border-blue-500 focus:bg-white focus:ring-blue-500">
                                    <option v-for="tipe in tipeUker" :key="tipe" :value="tipe">{{ tipe }}</option>
                                </select>
                                <span v-if="errors.tipe" class="mt-1 block text-xs text-rose-600">{{ errors.tipe[0] }}</span>
                            </label>
                        </div>

                        <div v-if="errors.id" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ errors.id[0] }}</div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
                        <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100" @click="tutupModal">Batal</button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700 disabled:opacity-60" :disabled="menyimpan" @click="simpanEdit">
                            <svg v-if="menyimpan" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" stroke-opacity=".25"/><path stroke-linecap="round" d="M21 12a9 9 0 00-9-9"/></svg>
                            {{ menyimpan ? 'Menyimpan…' : 'Simpan Perubahan' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
