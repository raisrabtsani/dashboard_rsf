<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { buatAdminApi } from '@/services/adminDomainApi';
import { formatAngka } from '@/utils/formatAngka';
import { useTableSort } from '@/utils/useTableSort';

/**
 * Halaman upload data aktual — SATU komponen untuk semua domain.
 *
 * Halaman per domain hanya meneruskan props; jangan menyalin komponen ini.
 */
const props = defineProps({
    domain: { type: String, required: true },
    judul: { type: String, required: true },
    kolomBerkas: { type: Array, required: true },
    /** Catatan aturan duplikat yang berbeda per domain. */
    catatanDuplikat: { type: String, default: 'Tanggal yang datanya sudah ada akan ditolak — hapus dulu tanggal tersebut, lalu unggah ulang.' },
    /** Label kolom nilai di tabel riwayat. */
    labelNilai: { type: String, default: 'Total Nilai' },
    /** Key nilai pada payload riwayat (Simpanan: total_saldo, Pinjaman: total). */
    keyNilai: { type: String, default: 'total' },
});

const api = buatAdminApi(props.domain);

const berkas = ref(null);
const riwayat = ref([]);
const memuat = ref(false);
const mengunggah = ref(false);
const pesan = ref(null);
const hapus = ref({ tahun: new Date().getFullYear(), bulan: new Date().getMonth() + 1 });

const sort = useTableSort('tanggal', 'desc');
const riwayatTerurut = computed(() => sort.urutkan(riwayat.value));

const KOLOM = computed(() => [
    { key: 'tanggal', label: 'Tanggal', kelas: 'text-left' },
    { key: 'jumlah_baris', label: 'Jumlah Baris', kelas: 'text-right' },
    { key: props.keyNilai, label: props.labelNilai, kelas: 'text-right' },
    { key: 'diunggah', label: 'Terakhir Diunggah', kelas: 'text-left' },
]);

const BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const lapor = (teks, jenis = 'sukses') => (pesan.value = { teks, jenis });
const laporGagal = (e) => lapor(e?.response?.data?.message ?? 'Terjadi kesalahan tak terduga.', 'gagal');

async function muat() {
    memuat.value = true;
    try {
        riwayat.value = await api.fetchRiwayat();
    } finally {
        memuat.value = false;
    }
}

async function kirim() {
    if (!berkas.value) return;

    mengunggah.value = true;
    pesan.value = null;

    try {
        lapor((await api.uploadAktual(berkas.value)).message);
        berkas.value = null;
        document.getElementById('berkas').value = '';
        await muat();
    } catch (e) {
        laporGagal(e);
    } finally {
        mengunggah.value = false;
    }
}

async function hapusSatuTanggal(tanggal) {
    if (!confirm(`Hapus seluruh data ${props.domain} tanggal ${tanggal}?`)) return;

    try {
        lapor((await api.hapusTanggal(tanggal)).message);
        await muat();
    } catch (e) {
        laporGagal(e);
    }
}

async function hapusSatuBulan() {
    const nama = `${BULAN[hapus.value.bulan - 1]} ${hapus.value.tahun}`;
    if (!confirm(`Hapus SEMUA data ${props.domain} pada ${nama}?`)) return;

    try {
        lapor((await api.hapusBulan(hapus.value.tahun, hapus.value.bulan)).message);
        await muat();
    } catch (e) {
        laporGagal(e);
    }
}

onMounted(muat);
</script>

<template>
    <Head :title="judul" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ judul }}</h2>
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
                    <h3 class="text-sm font-semibold text-gray-700">Unggah Berkas</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Format CSV atau Excel dengan kolom:
                        <code class="rounded bg-gray-100 px-1">{{ kolomBerkas.join(' | ') }}</code>.
                        Nilai dalam rupiah penuh.
                    </p>
                    <p class="mt-1 text-xs text-amber-600">{{ catatanDuplikat }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <input
                            id="berkas"
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls"
                            class="block text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                            @change="berkas = $event.target.files[0] ?? null"
                        />
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="!berkas || mengunggah"
                            @click="kirim"
                        >
                            {{ mengunggah ? 'Mengunggah…' : 'Unggah' }}
                        </button>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700">Hapus per Bulan</h3>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Bulan</span>
                            <select v-model.number="hapus.bulan" class="mt-1 block rounded-md border-gray-300 text-sm">
                                <option v-for="(nama, i) in BULAN" :key="nama" :value="i + 1">{{ nama }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Tahun</span>
                            <input v-model.number="hapus.tahun" type="number" class="mt-1 block w-28 rounded-md border-gray-300 text-sm" />
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700"
                            @click="hapusSatuBulan"
                        >
                            Hapus Bulan Ini
                        </button>
                    </div>
                </div>

                <div class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat" />

                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">Riwayat Upload</h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Diturunkan langsung dari data yang tersimpan, jadi selalu sesuai isi tabel.
                        </p>
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
                                <tr v-for="r in riwayatTerurut" :key="r.tanggal" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-800">{{ r.tanggal }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ r.jumlah_baris.toLocaleString('id-ID') }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ formatAngka(r[keyNilai]) }}
                                    </td>
                                    <td class="px-4 py-2 text-gray-500">{{ r.diunggah ?? '–' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                        <a :href="api.urlUnduh(r.tanggal)" class="text-indigo-600 hover:underline">Unduh</a>
                                        <button type="button" class="ms-3 text-rose-600 hover:underline" @click="hapusSatuTanggal(r.tanggal)">
                                            Hapus
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
