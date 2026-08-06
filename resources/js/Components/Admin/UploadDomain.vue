<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import ImportReportCard from '@/Components/Admin/ImportReportCard.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { buatAdminApi } from '@/services/adminDomainApi';
import { formatAngka } from '@/utils/formatAngka';

const props = defineProps({
    domain: { type: String, required: true },
    judul: { type: String, required: true },
    kolomBerkas: { type: Array, required: true },
    catatanDuplikat: {
        type: String,
        default: 'Tanggal yang datanya sudah ada akan ditolak — hapus dulu tanggal tersebut, lalu unggah ulang.',
    },
    labelNilai: { type: String, default: 'Total Nilai' },
    keyNilai: { type: String, default: 'total' },
    keyPeriode: { type: String, default: 'tanggal' },
    labelPeriode: { type: String, default: 'Tanggal' },
    massal: { type: String, default: 'bulan' },
    contohFormat: { type: String, default: '' },
    previewBeforeUpload: { type: Boolean, default: false },
});

const api = buatAdminApi(props.domain, { param: props.keyPeriode, massal: props.massal });

const berkas = ref(null);
const riwayat = ref([]);
const memuat = ref(false);
const mengunggah = ref(false);
const memvalidasi = ref(false);
const siapUnggah = ref(false);
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

function bagianPeriode(nilai) {
    const cocok = /^(\d{4})-(\d{2})(?:-(\d{2}))?/.exec(String(nilai ?? ''));
    return cocok
        ? { tahun: Number(cocok[1]), bulan: Number(cocok[2]), hari: Number(cocok[3] ?? 1) }
        : { tahun: null, bulan: null, hari: null };
}

function formatTanggal(nilai) {
    const p = bagianPeriode(nilai);
    if (!p.tahun) return nilai ?? '–';
    return `${String(p.hari).padStart(2, '0')} ${BULAN[p.bulan - 1]} ${p.tahun}`;
}

function formatWaktu(nilai) {
    if (!nilai) return '–';
    const cocok = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(nilai));
    if (!cocok) return nilai;
    return `${cocok[3]} ${BULAN[Number(cocok[2]) - 1]} ${cocok[1]}, ${cocok[4]}:${cocok[5]}`;
}

const tahunTersedia = computed(() =>
    [...new Set(riwayat.value.map((r) => bagianPeriode(r[props.keyPeriode]).tahun).filter(Boolean))]
        .sort((a, b) => b - a),
);

const riwayatTerfilter = computed(() =>
    riwayat.value.filter((r) => {
        const p = bagianPeriode(r[props.keyPeriode]);
        return (!filterTahun.value || p.tahun === Number(filterTahun.value))
            && (!filterBulan.value || p.bulan === Number(filterBulan.value));
    }),
);

const totalBaris = computed(() =>
    riwayatTerfilter.value.reduce((total, r) => total + Number(r.jumlah_baris ?? 0), 0),
);
const totalNilai = computed(() =>
    riwayatTerfilter.value.reduce((total, r) => total + Number(r[props.keyNilai] ?? 0), 0),
);

const jumlahValidPreview = computed(() => Number(laporan.value?.valid ?? 0));
const jumlahTidakValidPreview = computed(() => Number(laporan.value?.tidak_valid ?? 0));

const semuaTerpilih = computed(() =>
    riwayatTerfilter.value.length > 0
    && riwayatTerfilter.value.every((r) => terpilih.value.includes(String(r[props.keyPeriode]))),
);

function toggleSemua() {
    const kunciTerlihat = riwayatTerfilter.value.map((r) => String(r[props.keyPeriode]));

    if (semuaTerpilih.value) {
        terpilih.value = terpilih.value.filter((k) => !kunciTerlihat.includes(k));
        return;
    }

    terpilih.value = [...new Set([...terpilih.value, ...kunciTerlihat])];
}

const lapor = (teks, jenis = 'sukses') => (pesan.value = { teks, jenis });

function pesanValidasiLaravel(data) {
    const errors = data?.errors;
    if (!errors || typeof errors !== 'object') return null;

    return Object.values(errors)
        .flat()
        .map((item) => String(item ?? '').trim())
        .find(Boolean) ?? null;
}

function pesanError(e) {
    const status = Number(e?.response?.status ?? 0);
    const dariServer = e?.response?.data?.message ?? pesanValidasiLaravel(e?.response?.data);

    if (dariServer) return String(dariServer);

    if (status === 413) {
        return 'Ukuran file ditolak web server. Naikkan client_max_body_size, upload_max_filesize, dan post_max_size.';
    }

    const pesanJs = String(e?.message ?? '').trim();
    if (pesanJs.includes('is not in the route list')) {
        return 'Route Preview & Validasi belum aktif. Jalankan php artisan optimize:clear lalu npm run build.';
    }

    if (pesanJs) return pesanJs;
    if (status) return `Permintaan gagal dengan HTTP ${status}. Periksa storage/logs/laravel.log.`;

    return 'Permintaan gagal sebelum mencapai server. Periksa Console dan Network browser.';
}

const laporGagal = (e) => lapor(pesanError(e), 'gagal');

async function muat() {
    memuat.value = true;
    try {
        riwayat.value = await api.fetchRiwayat();
        const tersedia = new Set(riwayat.value.map((r) => String(r[props.keyPeriode])));
        terpilih.value = terpilih.value.filter((k) => tersedia.has(k));
    } finally {
        memuat.value = false;
    }
}

function pilihBerkas(event) {
    berkas.value = event.target.files?.[0] ?? null;
    laporan.value = null;
    siapUnggah.value = false;
    pesan.value = null;
}

function bersihkanBerkas() {
    berkas.value = null;
    laporan.value = null;
    siapUnggah.value = false;
    pesan.value = null;

    const input = document.getElementById(`berkas-${props.domain}`);
    if (input) input.value = '';
}

async function preview() {
    if (!berkas.value || !props.previewBeforeUpload) return;

    memvalidasi.value = true;
    pesan.value = null;
    siapUnggah.value = false;

    try {
        namaBerkasLaporan.value = berkas.value?.name ?? 'hasil-import';
        const respons = await api.previewAktual(berkas.value);
        laporan.value = respons?.hasil?.laporan ?? null;
        siapUnggah.value = jumlahValidPreview.value > 0;
        lapor(respons.message);
    } catch (e) {
        laporan.value = null;
        siapUnggah.value = false;
        laporGagal(e);
    } finally {
        memvalidasi.value = false;
    }
}

async function kirim() {
    if (!berkas.value) return;
    if (props.previewBeforeUpload && !siapUnggah.value) return;

    mengunggah.value = true;
    pesan.value = null;

    try {
        namaBerkasLaporan.value = berkas.value?.name ?? 'hasil-import';
        const respons = await api.uploadAktual(berkas.value);
        laporan.value = respons?.hasil?.laporan ?? laporan.value;
        lapor(respons.message);
        berkas.value = null;
        siapUnggah.value = false;
        const input = document.getElementById(`berkas-${props.domain}`);
        if (input) input.value = '';
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        mengunggah.value = false;
    }
}

async function hapusSatuTanggal(nilai) {
    if (!confirm(`Hapus seluruh data ${props.domain} pada ${formatTanggal(nilai)}?`)) return;

    try {
        lapor((await api.hapusTanggal(nilai)).message);
        terpilih.value = terpilih.value.filter((k) => k !== String(nilai));
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
        const pilihan = [...terpilih.value];
        for (const nilai of pilihan) {
            await api.hapusTanggal(nilai);
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
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Administrasi Data</p>
                <h2 class="mt-1 text-xl font-bold text-slate-900">{{ judul }}</h2>
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
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">Upload Data</p>
                                <h3 class="mt-1 text-xl font-bold">Unggah Berkas Aktual</h3>
                                <p class="mt-1 max-w-3xl text-xs leading-relaxed text-white/75">
                                    Format CSV atau Excel. Kolom: {{ kolomBerkas.join(' | ') }}.
                                </p>
                                <div v-if="contohFormat" class="mt-3 max-w-4xl overflow-x-auto rounded-xl bg-black/15 px-3 py-2 ring-1 ring-white/10">
                                    <code class="whitespace-pre text-[11px] leading-5 text-cyan-50">{{ contohFormat }}</code>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <input
                                    :id="`berkas-${domain}`"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    class="max-w-full rounded-xl bg-white/10 text-sm text-white file:mr-3 file:rounded-xl file:border-0 file:bg-white file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-brand-700 hover:file:bg-brand-50"
                                    @change="pilihBerkas"
                                />
                                <button
                                    type="button"
                                    class="inline-flex h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-bold text-brand-700 shadow-lg transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!berkas || mengunggah || memvalidasi"
                                    @click="previewBeforeUpload ? preview() : kirim()"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2l4 4h-3v5H9V6H6l4-4z"/><path d="M4 12h2v3h8v-3h2v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4z"/></svg>
                                    {{ memvalidasi ? 'Memvalidasi…' : (mengunggah ? 'Mengunggah…' : (previewBeforeUpload ? 'Preview & Validasi' : 'Unggah Data')) }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs leading-relaxed text-amber-700 sm:px-6">
                        {{ catatanDuplikat }}
                    </div>
                </section>

                <ImportReportCard :laporan="laporan" :nama-berkas="namaBerkasLaporan" :preview-only="previewBeforeUpload && siapUnggah" />

                <section
                    v-if="previewBeforeUpload && laporan"
                    class="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_rgba(15,23,42,0.07)] sm:flex-row sm:items-center sm:justify-between sm:p-6"
                >
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Tahap Berikutnya</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-900">Upload hasil validasi</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ jumlahValidPreview.toLocaleString('id-ID') }} baris valid akan masuk. {{ jumlahTidakValidPreview.toLocaleString('id-ID') }} baris error dilewati.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                            @click="bersihkanBerkas"
                        >
                            Pilih File Ulang
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!siapUnggah || mengunggah"
                            @click="kirim"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2l4 4h-3v5H9V6H6l4-4z"/><path d="M4 12h2v3h8v-3h2v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4z"/></svg>
                            {{ mengunggah ? 'Mengunggah…' : `Upload ${jumlahValidPreview.toLocaleString('id-ID')} Baris Valid` }}
                        </button>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_rgba(15,23,42,0.07)] sm:p-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Riwayat Upload</p>
                            <h3 class="mt-1 text-lg font-bold text-slate-900">Data per Tanggal</h3>
                            <p class="mt-1 text-xs text-slate-500">Filter bulan dan tahun, lalu pilih satu, beberapa, atau semua baris untuk dihapus.</p>
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
                                        <th class="w-12 px-4 py-3 text-center">
                                            <input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" :checked="semuaTerpilih" @change="toggleSemua" />
                                        </th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ labelPeriode }}</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Jumlah Baris</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ labelNilai }}</th>
                                        <th class="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">Terakhir Diunggah</th>
                                        <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-slate-400">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="r in riwayatTerfilter" :key="r[keyPeriode]" class="transition hover:bg-brand-50/40">
                                        <td class="px-4 py-3 text-center">
                                            <input v-model="terpilih" type="checkbox" :value="String(r[keyPeriode])" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-bold text-slate-800">{{ formatTanggal(r[keyPeriode]) }}</p>
                                            <p class="mt-0.5 text-[11px] text-slate-400">{{ r[keyPeriode] }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-700">{{ Number(r.jumlah_baris ?? 0).toLocaleString('id-ID') }}</td>
                                        <td class="px-4 py-3 text-right font-bold tabular-nums text-brand-700">{{ formatAngka(r[keyNilai]) }}</td>
                                        <td class="px-4 py-3 text-slate-500">{{ formatWaktu(r.diunggah) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <a :href="api.urlUnduh(r[keyPeriode])" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-bold text-brand-700 transition hover:bg-brand-50">Unduh</a>
                                            <button type="button" class="ml-1 inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="hapusSatuTanggal(r[keyPeriode])">Hapus</button>
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
