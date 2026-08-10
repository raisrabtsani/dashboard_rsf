<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import ImportReportCard from '@/Components/Admin/ImportReportCard.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { buatAdminApiBulanan } from '@/services/adminDomainApi';
import { formatAngka } from '@/utils/formatAngka';

const props = defineProps({
    domain: { type: String, required: true },
    judul: { type: String, required: true },
    kolomBerkas: { type: Array, required: true },
    labelNilai: { type: String, default: 'Total Nilai' },
    catatan: {
        type: String,
        default: 'Nilai kumulatif YTD. Mengunggah ulang periode yang sama akan menimpa (upsert), bukan menggandakan.',
    },
});

const api = buatAdminApiBulanan(props.domain);

const berkas = ref(null);
const riwayat = ref([]);
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

const kunciBaris = (r) => `${r.tahun}-${String(r.bulan).padStart(2, '0')}`;
const formatPeriode = (r) => `01 ${BULAN[Number(r.bulan) - 1]} ${r.tahun}`;

function formatWaktu(nilai) {
    if (!nilai) return '–';
    const cocok = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(nilai));
    if (!cocok) return nilai;
    return `${cocok[3]} ${BULAN[Number(cocok[2]) - 1]} ${cocok[1]}, ${cocok[4]}:${cocok[5]}`;
}

const tahunTersedia = computed(() =>
    [...new Set(riwayat.value.map((r) => Number(r.tahun)).filter(Boolean))].sort((a, b) => b - a),
);

const riwayatTerfilter = computed(() =>
    riwayat.value.filter((r) =>
        (!filterTahun.value || Number(r.tahun) === Number(filterTahun.value))
        && (!filterBulan.value || Number(r.bulan) === Number(filterBulan.value)),
    ),
);

const totalBaris = computed(() =>
    riwayatTerfilter.value.reduce((total, r) => total + Number(r.jumlah_baris ?? 0), 0),
);
const totalNilai = computed(() =>
    riwayatTerfilter.value.reduce((total, r) => total + Number(r.total ?? 0), 0),
);

const semuaTerpilih = computed(() =>
    riwayatTerfilter.value.length > 0
    && riwayatTerfilter.value.every((r) => terpilih.value.includes(kunciBaris(r))),
);

function toggleSemua() {
    const terlihat = riwayatTerfilter.value.map(kunciBaris);

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
        riwayat.value = await api.fetchRiwayat();
        const tersedia = new Set(riwayat.value.map(kunciBaris));
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
        const respons = await api.uploadAktual(berkas.value);
        laporan.value = respons?.hasil?.laporan ?? null;
        lapor(respons.message);
        berkas.value = null;
        document.getElementById(`berkas-${props.domain}`).value = '';
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        mengunggah.value = false;
    }
}

async function hapusSatuPeriode(r) {
    if (!confirm(`Hapus seluruh data ${props.domain} periode ${formatPeriode(r)}?`)) return;

    try {
        lapor((await api.hapusPeriode(r.tahun, r.bulan)).message);
        terpilih.value = terpilih.value.filter((k) => k !== kunciBaris(r));
        await muat();
    } catch (e) {
        laporGagal(e);
    }
}

async function hapusTerpilih() {
    if (!terpilih.value.length) return;
    if (!confirm(`Hapus ${terpilih.value.length} periode yang dipilih? Tindakan ini tidak dapat dibatalkan.`)) return;

    menghapus.value = true;
    pesan.value = null;

    try {
        const peta = new Map(riwayat.value.map((r) => [kunciBaris(r), r]));
        const pilihan = terpilih.value.map((k) => peta.get(k)).filter(Boolean);

        for (const r of pilihan) {
            await api.hapusPeriode(r.tahun, r.bulan);
        }

        lapor(`${pilihan.length} periode terpilih berhasil dihapus.`);
        terpilih.value = [];
        await muat();
    } catch (e) {
        laporGagal(e);
        await muat();
    } finally {
        menghapus.value = false;
    }
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
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Administrasi Data</p>
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
                    class="rounded-2xl border p-4 text-sm shadow-sm"
                    :class="pesan.jenis === 'sukses'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : 'border-rose-200 bg-rose-50 text-rose-800'"
                >
                    {{ pesan.teks }}
                </div>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_12px_34px_rgba(15,23,42,0.08)]">
                    <div class="bg-gradient-to-r from-brand-700 via-brand-600 to-brand-500 px-5 py-5 text-white sm:px-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">Upload Data Bulanan</p>
                                <h3 class="mt-1 text-xl font-bold">Unggah Berkas Aktual</h3>
                                <p class="mt-1 max-w-3xl text-xs leading-relaxed text-white/75">Kolom: {{ kolomBerkas.join(' | ') }}.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    :id="`berkas-${domain}`"
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
                                    {{ mengunggah ? 'Mengunggah…' : 'Unggah Data' }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs leading-relaxed text-amber-700 sm:px-6">{{ catatan }}</div>
                </section>

                <ImportReportCard :laporan="laporan" :nama-berkas="namaBerkasLaporan" />

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_rgba(15,23,42,0.07)] sm:p-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Riwayat Upload</p>
                            <h3 class="mt-1 text-lg font-bold text-slate-900">Data per Tanggal Periode</h3>
                            <p class="mt-1 text-xs text-slate-500">Filter bulan dan tahun, lalu pilih data yang akan dihapus.</p>
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
                            <button type="button" class="self-end rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-50" @click="resetFilter">Reset Filter</button>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl bg-brand-50 p-4 ring-1 ring-brand-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-brand-500">Jumlah Periode</p>
                            <p class="mt-1 text-2xl font-extrabold text-brand-700">{{ riwayatTerfilter.length }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Jumlah Baris</p>
                            <p class="mt-1 text-2xl font-extrabold text-slate-800">{{ totalBaris.toLocaleString('id-ID') }}</p>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-600">{{ labelNilai }}</p>
                            <p class="mt-1 text-2xl font-extrabold text-emerald-700">{{ formatAngka(totalNilai) }}</p>
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
                                        <th class="w-12 px-4 py-3 text-center"><input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" :checked="semuaTerpilih" @change="toggleSemua" /></th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">Tanggal Periode</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Jumlah Baris</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ labelNilai }}</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">Terakhir Diunggah</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="r in riwayatTerfilter" :key="kunciBaris(r)" class="transition hover:bg-brand-50/40">
                                        <td class="px-4 py-3 text-center"><input v-model="terpilih" type="checkbox" :value="kunciBaris(r)" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" /></td>
                                        <td class="px-4 py-3">
                                            <p class="font-bold text-slate-800">{{ formatPeriode(r) }}</p>
                                            <p class="mt-0.5 text-[11px] text-slate-400">Periode {{ String(r.bulan).padStart(2, '0') }}/{{ r.tahun }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-700">{{ Number(r.jumlah_baris ?? 0).toLocaleString('id-ID') }}</td>
                                        <td class="px-4 py-3 text-right font-bold tabular-nums text-brand-700">{{ formatAngka(r.total) }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ formatWaktu(r.diunggah) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <a :href="api.urlUnduh(r.tahun, r.bulan)" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-bold text-brand-700 transition hover:bg-brand-50">Unduh</a>
                                            <button type="button" class="ml-1 inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="hapusSatuPeriode(r)">Hapus</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!riwayatTerfilter.length">
                                        <td colspan="6" class="px-4 py-12 text-center">
                                            <p class="font-semibold text-slate-500">Tidak ada data pada filter ini.</p>
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
