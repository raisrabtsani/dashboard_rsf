<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import ImportReportCard from '@/Components/Admin/ImportReportCard.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { buatAdminApi } from '@/services/adminDomainApi';
import { formatAngka } from '@/utils/formatAngka';

const props = defineProps({
    domain: { type: String, required: true },
    judul: { type: String, required: true },
    kolomBerkas: { type: Array, required: true },
    labelKelompok: { type: String, default: 'Produk' },
    petunjukKpi: { type: String, default: '' },
});

const api = buatAdminApi(props.domain);

const berkas = ref(null);
const ringkasan = ref([]);
const memuat = ref(false);
const mengunggah = ref(false);
const menghapus = ref(false);
const pesan = ref(null);
const laporan = ref(null);
const namaBerkasLaporan = ref('hasil-import');
const filterTahun = ref('');
const filterBulan = ref('');
const terpilih = ref([]);

const BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const kunciBaris = (r) => `${r.tahun}|${r.bulan}|${r.kelompok}`;
const formatPeriode = (tahun, bulan) => `01 ${BULAN[Number(bulan) - 1]} ${tahun}`;

function formatWaktu(nilai) {
    if (!nilai) return '–';
    const cocok = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(nilai));
    if (!cocok) return nilai;
    return `${cocok[3]} ${BULAN[Number(cocok[2]) - 1]} ${cocok[1]}, ${cocok[4]}:${cocok[5]}`;
}

const tahunTersedia = computed(() =>
    [...new Set(ringkasan.value.map((r) => Number(r.tahun)).filter(Boolean))].sort((a, b) => b - a),
);

const ringkasanTerfilter = computed(() =>
    ringkasan.value.filter((r) =>
        (!filterTahun.value || Number(r.tahun) === Number(filterTahun.value))
        && (!filterBulan.value || Number(r.bulan) === Number(filterBulan.value)),
    ),
);

const totalBaris = computed(() =>
    ringkasanTerfilter.value.reduce((total, r) => total + Number(r.jumlah_baris ?? 0), 0),
);
const totalTarget = computed(() =>
    ringkasanTerfilter.value.reduce((total, r) => total + Number(r.total_target ?? 0), 0),
);
const jumlahPeriode = computed(() =>
    new Set(ringkasanTerfilter.value.map((r) => `${r.tahun}-${r.bulan}`)).size,
);

const semuaTerpilih = computed(() =>
    ringkasanTerfilter.value.length > 0
    && ringkasanTerfilter.value.every((r) => terpilih.value.includes(kunciBaris(r))),
);

function toggleSemua() {
    const terlihat = ringkasanTerfilter.value.map(kunciBaris);

    if (semuaTerpilih.value) {
        terpilih.value = terpilih.value.filter((k) => !terlihat.includes(k));
        return;
    }

    terpilih.value = [...new Set([...terpilih.value, ...terlihat])];
}

const lapor = (teks, jenis = 'sukses') => (pesan.value = { teks, jenis });
const laporGagal = (e) => lapor(e?.response?.data?.message ?? 'Terjadi kesalahan tak terduga.', 'gagal');

async function muat() {
    memuat.value = true;
    try {
        ringkasan.value = await api.fetchRka();
        const tersedia = new Set(ringkasan.value.map(kunciBaris));
        terpilih.value = terpilih.value.filter((k) => tersedia.has(k));
    } finally {
        memuat.value = false;
    }
}

async function kirim() {
    if (!berkas.value) return;

    mengunggah.value = true;
    pesan.value = null;

    try {
        namaBerkasLaporan.value = berkas.value?.name ?? 'hasil-import';
        const respons = await api.uploadRka(berkas.value);
        laporan.value = respons?.hasil?.laporan ?? null;
        lapor(respons.message);
        berkas.value = null;
        document.getElementById(`berkas-rka-${props.domain}`).value = '';
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        mengunggah.value = false;
    }
}

function dataPilihan(kunciList) {
    const peta = new Map(ringkasan.value.map((r) => [kunciBaris(r), r]));

    return kunciList
        .map((k) => peta.get(k))
        .filter(Boolean)
        .map((r) => ({
            tahun: Number(r.tahun),
            bulan: Number(r.bulan),
            kelompok: String(r.kelompok),
        }));
}

async function hapusDaftar(kunciList) {
    const pilihan = dataPilihan(kunciList);
    if (!pilihan.length) return;

    menghapus.value = true;
    pesan.value = null;

    try {
        lapor((await api.hapusRkaPilihan(pilihan)).message);
        terpilih.value = terpilih.value.filter((k) => !kunciList.includes(k));
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        menghapus.value = false;
    }
}

async function hapusTerpilih() {
    if (!terpilih.value.length) return;
    if (!confirm(`Hapus ${terpilih.value.length} kelompok RKA yang dipilih? Tindakan ini tidak dapat dibatalkan.`)) return;
    await hapusDaftar([...terpilih.value]);
}

async function hapusSatu(r) {
    const kunci = kunciBaris(r);
    if (!confirm(`Hapus RKA ${r.kelompok} periode ${formatPeriode(r.tahun, r.bulan)}?`)) return;
    await hapusDaftar([kunci]);
}

function resetFilter() {
    filterTahun.value = '';
    filterBulan.value = '';
}

onMounted(muat);
</script>

<template>
    <Head :title="judul" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Administrasi Target</p>
                    <h2 class="mt-1 text-xl font-bold text-slate-900">{{ judul }}</h2>
                </div>
                <Link :href="route('admin.index')" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 shadow-sm transition hover:border-blue-200 hover:text-blue-700">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                    Kembali ke Admin
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="pesan"
                    class="flex items-start gap-3 rounded-2xl border p-4 text-sm shadow-sm"
                    :class="pesan.jenis === 'sukses'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : 'border-rose-200 bg-rose-50 text-rose-800'"
                >
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/80">
                        <svg v-if="pesan.jenis === 'sukses'" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                        <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 11a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v4a1 1 0 102 0V6z" clip-rule="evenodd" /></svg>
                    </span>
                    <span>{{ pesan.teks }}</span>
                </div>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_12px_34px_rgba(15,23,42,0.08)]">
                    <div class="bg-gradient-to-r from-brand-700 via-brand-600 to-brand-500 px-5 py-5 text-white sm:px-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">RKA Tahunan</p>
                                <h3 class="mt-1 text-xl font-bold">Unggah Target</h3>
                                <p class="mt-1 max-w-3xl text-xs leading-relaxed text-white/75">
                                    Kolom: {{ kolomBerkas.join(' | ') }}. Bulan boleh angka 1–12 atau nama bulan.
                                </p>
                                <p v-if="petunjukKpi" class="mt-1 max-w-4xl text-xs font-semibold leading-relaxed text-cyan-100">
                                    Pemetaan KPI: {{ petunjukKpi }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    :id="`berkas-rka-${domain}`"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    class="max-w-full rounded-xl bg-white/10 text-sm text-white file:mr-3 file:rounded-xl file:border-0 file:bg-white file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-brand-700 hover:file:bg-brand-50"
                                    @change="berkas = $event.target.files[0] ?? null"
                                />
                                <button
                                    type="button"
                                    class="inline-flex h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-bold text-brand-700 shadow-lg transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!berkas || mengunggah"
                                    @click="kirim"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2l4 4h-3v5H9V6H6l4-4z"/><path d="M4 12h2v3h8v-3h2v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4z"/></svg>
                                    {{ mengunggah ? 'Mengunggah…' : 'Unggah RKA' }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-blue-100 bg-blue-50 px-5 py-3 text-xs leading-relaxed text-blue-700 sm:px-6">
                        Data RKA ditimpa bila kombinasi kuncinya sudah ada. Target kosong dilewati dan tidak disimpan.
                    </div>
                </section>

                <ImportReportCard :laporan="laporan" :nama-berkas="namaBerkasLaporan" />

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_rgba(15,23,42,0.07)] sm:p-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Daftar Target</p>
                            <h3 class="mt-1 text-lg font-bold text-slate-900">RKA per Tanggal Periode</h3>
                            <p class="mt-1 text-xs text-slate-500">Filter bulan dan tahun, lalu hapus satu, beberapa, atau semua hasil filter.</p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:flex">
                            <label class="block">
                                <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Bulan</span>
                                <select v-model="filterBulan" class="h-11 min-w-44 rounded-xl border-slate-200 bg-slate-50 text-sm font-semibold text-slate-700 focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">Semua Bulan</option>
                                    <option v-for="(nama, i) in BULAN" :key="nama" :value="i + 1">{{ nama }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Tahun</span>
                                <select v-model="filterTahun" class="h-11 min-w-36 rounded-xl border-slate-200 bg-slate-50 text-sm font-semibold text-slate-700 focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">Semua Tahun</option>
                                    <option v-for="tahun in tahunTersedia" :key="tahun" :value="tahun">{{ tahun }}</option>
                                </select>
                            </label>
                            <button type="button" class="self-end rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-50" @click="resetFilter">
                                Reset Filter
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl bg-brand-50 p-4 ring-1 ring-brand-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-brand-500">Jumlah Periode</p>
                            <p class="mt-1 text-2xl font-extrabold text-brand-700">{{ jumlahPeriode }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Jumlah Baris</p>
                            <p class="mt-1 text-2xl font-extrabold text-slate-800">{{ totalBaris.toLocaleString('id-ID') }}</p>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-600">Total Target</p>
                            <p class="mt-1 text-2xl font-extrabold text-emerald-700">{{ formatAngka(totalTarget) }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-600">
                            <input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" :checked="semuaTerpilih" @change="toggleSemua" />
                            Pilih semua hasil filter
                        </label>
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold text-slate-500">{{ terpilih.length }} dipilih</span>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="!terpilih.length || menghapus"
                                @click="hapusTerpilih"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6 6h8l-.6 10.1a1 1 0 01-1 .9H7.6a1 1 0 01-1-.9L6 6zm2-3h4l1 1h3v2H4V4h3l1-1z"/></svg>
                                {{ menghapus ? 'Menghapus…' : 'Hapus Terpilih' }}
                            </button>
                        </div>
                    </div>

                    <div class="relative mt-4 overflow-hidden rounded-2xl border border-slate-200">
                        <LoadingOverlay :show="memuat" />
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="w-12 px-4 py-3 text-center">
                                            <input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" :checked="semuaTerpilih" @change="toggleSemua" />
                                        </th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">Tanggal Periode</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ labelKelompok }}</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Jumlah Baris</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Target</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">Terakhir Diubah</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="r in ringkasanTerfilter" :key="kunciBaris(r)" class="transition hover:bg-brand-50/40">
                                        <td class="px-4 py-3 text-center">
                                            <input v-model="terpilih" type="checkbox" :value="kunciBaris(r)" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-bold text-slate-800">{{ formatPeriode(r.tahun, r.bulan) }}</p>
                                            <p class="mt-0.5 text-[11px] text-slate-400">Periode {{ String(r.bulan).padStart(2, '0') }}/{{ r.tahun }}</p>
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-slate-700">{{ r.kelompok }}</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-700">{{ Number(r.jumlah_baris ?? 0).toLocaleString('id-ID') }}</td>
                                        <td class="px-4 py-3 text-right font-bold tabular-nums text-brand-700">{{ formatAngka(r.total_target) }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ formatWaktu(r.diubah) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <button type="button" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="hapusSatu(r)">Hapus</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!ringkasanTerfilter.length">
                                        <td colspan="7" class="px-4 py-12 text-center">
                                            <p class="font-semibold text-slate-500">Tidak ada RKA pada filter ini.</p>
                                            <p class="mt-1 text-xs text-slate-400">Ubah bulan atau tahun untuk melihat periode lain.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
