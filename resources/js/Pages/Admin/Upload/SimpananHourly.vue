<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import ImportReportCard from '@/Components/Admin/ImportReportCard.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { formatAngka } from '@/utils/formatAngka';
import { useTableSort } from '@/utils/useTableSort';

/**
 * Halaman upload DPK Hourly — TIDAK memakai komponen UploadDomain bersama
 * karena domain ini butuh input JAM yang tidak ada di berkas, dan riwayatnya
 * berkunci (tanggal, jam) alih-alih satu periode.
 */
defineProps({
    kolomBerkas: { type: Array, required: true },
});

const berkas = ref(null);
const jam = ref(new Date().getHours());
const riwayat = ref([]);
const memuat = ref(false);
const mengunggah = ref(false);
const pesan = ref(null);
const laporan = ref(null);
const namaBerkasLaporan = ref('hasil-import');

const sort = useTableSort('periode', 'desc');
const riwayatTerurut = computed(() => sort.urutkan(riwayat.value));

const KOLOM = [
    { key: 'periode', label: 'Tanggal & Jam', kelas: 'text-left' },
    { key: 'jumlah_baris', label: 'Jumlah Baris', kelas: 'text-right' },
    { key: 'total', label: 'Total Saldo', kelas: 'text-right' },
    { key: 'diunggah', label: 'Terakhir Diunggah', kelas: 'text-left' },
];

const lapor = (teks, jenis = 'sukses') => (pesan.value = { teks, jenis });
const laporGagal = (e) => lapor(e?.response?.data?.message ?? 'Terjadi kesalahan tak terduga.', 'gagal');

async function muat() {
    memuat.value = true;
    try {
        riwayat.value = (await axios.get(route('admin.upload.simpanan-hourly.riwayat'))).data.riwayat ?? [];
    } finally {
        memuat.value = false;
    }
}

async function kirim() {
    if (!berkas.value) return;

    mengunggah.value = true;
    pesan.value = null;

    const data = new FormData();
    data.append('berkas', berkas.value);
    data.append('jam', jam.value);

    try {
        namaBerkasLaporan.value = berkas.value?.name ?? 'hasil-import';
        const respons = (await axios.post(route('admin.upload.simpanan-hourly.store'), data)).data;
        laporan.value = respons?.hasil?.laporan ?? null;
        lapor(respons.message);
        berkas.value = null;
        document.getElementById('berkas').value = '';
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        mengunggah.value = false;
    }
}

async function hapusJam(r) {
    if (!confirm(`Hapus data ${r.tanggal} pukul ${String(r.jam).padStart(2, '0')}:00?`)) return;

    try {
        lapor(
            (await axios.delete(route('admin.upload.simpanan-hourly.hapus-jam', { tanggal: r.tanggal }), {
                data: { jam: r.jam },
            })).data.message,
        );
        await muat();
    } catch (e) {
        laporGagal(e);
    }
}

async function hapusTanggal(tanggal) {
    if (!confirm(`Hapus SEMUA jam pada tanggal ${tanggal}?`)) return;

    try {
        lapor((await axios.delete(route('admin.upload.simpanan-hourly.hapus', { tanggal }))).data.message);
        await muat();
    } catch (e) {
        laporGagal(e);
    }
}

onMounted(muat);
</script>

<template>
    <Head title="Upload DPK Hourly" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Admin — Upload DPK Hourly
                </h2>
                <Link :href="route('admin.index')" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 shadow-sm transition hover:border-blue-200 hover:text-blue-700">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                    Kembali ke Admin
                </Link>
            </div>
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

                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700">Unggah Posisi per Jam</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Format berkas SAMA dengan Simpanan harian:
                        <code class="rounded bg-gray-100 px-1">{{ kolomBerkas.join(' | ') }}</code>.
                    </p>
                    <p class="mt-1 text-xs text-amber-600">
                        Berkas sumber tidak membawa informasi jam — pilih jamnya di sini.
                        Mengunggah ulang jam yang sama akan MENIMPA angkanya, bukan menggandakan.
                    </p>

                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <input
                            id="berkas"
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls"
                            class="block text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                            @change="berkas = $event.target.files[0] ?? null"
                        />
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Jam posisi</span>
                            <select v-model.number="jam" class="mt-1 block rounded-md border-gray-300 text-sm">
                                <option v-for="j in 24" :key="j - 1" :value="j - 1">
                                    {{ String(j - 1).padStart(2, '0') }}:00
                                </option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                            :disabled="!berkas || mengunggah"
                            @click="kirim"
                        >
                            {{ mengunggah ? 'Mengunggah…' : 'Unggah' }}
                        </button>
                    </div>
                </div>

                <ImportReportCard :laporan="laporan" :nama-berkas="namaBerkasLaporan" />

                <div class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat" />

                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">Riwayat Upload</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        v-for="k in KOLOM"
                                        :key="k.key"
                                        scope="col"
                                        class="cursor-pointer select-none px-4 py-2 text-xs font-semibold text-gray-500"
                                        :class="k.kelas"
                                        @click="sort.urutkanKolom(k.key)"
                                    >
                                        {{ k.label }}
                                        <SortArrow :arah="sort.arahUntuk(k.key)" />
                                    </th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="r in riwayatTerurut" :key="r.periode" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-800">{{ r.periode }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ r.jumlah_baris.toLocaleString('id-ID') }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(r.total) }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ r.diunggah ?? '–' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                        <button type="button" class="text-rose-600 hover:underline" @click="hapusJam(r)">
                                            Hapus jam
                                        </button>
                                        <button
                                            type="button"
                                            class="ms-3 text-rose-600 hover:underline"
                                            @click="hapusTanggal(r.tanggal)"
                                        >
                                            Hapus tanggal
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!riwayatTerurut.length">
                                    <td colspan="5" class="px-4 py-6 text-center text-gray-400">Belum ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
