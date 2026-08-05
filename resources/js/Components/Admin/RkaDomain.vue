<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { buatAdminApi } from '@/services/adminDomainApi';
import { formatAngka } from '@/utils/formatAngka';

/**
 * Halaman kelola RKA — SATU komponen untuk semua domain.
 */
const props = defineProps({
    domain: { type: String, required: true },
    judul: { type: String, required: true },
    kolomBerkas: { type: Array, required: true },
    /** Label kolom pengelompokan (Simpanan: Produk, Pinjaman: Kualitas). */
    labelKelompok: { type: String, default: 'Produk' },
});

const api = buatAdminApi(props.domain);

const berkas = ref(null);
const ringkasan = ref([]);
const memuat = ref(false);
const mengunggah = ref(false);
const pesan = ref(null);

const perTahun = computed(() => {
    const peta = new Map();

    for (const r of ringkasan.value) {
        if (!peta.has(r.tahun)) peta.set(r.tahun, { tahun: r.tahun, baris: [], total: 0 });
        const grup = peta.get(r.tahun);
        grup.baris.push(r);
        grup.total += r.total_target ?? 0;
    }

    return [...peta.values()].sort((a, b) => b.tahun - a.tahun);
});

const lapor = (teks, jenis = 'sukses') => (pesan.value = { teks, jenis });

async function muat() {
    memuat.value = true;
    try {
        ringkasan.value = await api.fetchRka();
    } finally {
        memuat.value = false;
    }
}

async function kirim() {
    if (!berkas.value) return;

    mengunggah.value = true;
    pesan.value = null;

    try {
        lapor((await api.uploadRka(berkas.value)).message);
        berkas.value = null;
        document.getElementById('berkas-rka').value = '';
        await muat();
    } catch (e) {
        lapor(e?.response?.data?.message ?? 'Terjadi kesalahan tak terduga.', 'gagal');
    } finally {
        mengunggah.value = false;
    }
}

async function hapusTahun(tahun) {
    if (!confirm(`Hapus SELURUH target RKA tahun ${tahun}?`)) return;

    try {
        lapor((await api.hapusRkaTahun(tahun)).message);
        await muat();
    } catch (e) {
        lapor(e?.response?.data?.message ?? 'Gagal menghapus.', 'gagal');
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
                    <h3 class="text-sm font-semibold text-gray-700">Unggah Target Tahunan</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Kolom: <code class="rounded bg-gray-100 px-1">{{ kolomBerkas.join(' | ') }}</code>.
                        Nilai target dalam rupiah penuh. Bulan boleh angka 1-12 atau nama bulan.
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Berbeda dengan data aktual, RKA <strong>ditimpa</strong> bila kombinasi kuncinya
                        sudah ada — target boleh direvisi sepanjang tahun berjalan. Sel target kosong
                        dianggap tidak punya target dan dilewati.
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <input
                            id="berkas-rka"
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls"
                            class="block text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                            @change="berkas = $event.target.files[0] ?? null"
                        />
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

                <div class="relative space-y-4">
                    <LoadingOverlay :show="memuat" />

                    <div v-for="grup in perTahun" :key="grup.tahun" class="rounded-lg bg-white shadow ring-1 ring-gray-100">
                        <div class="flex items-center justify-between border-b border-gray-100 p-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-700">RKA {{ grup.tahun }}</h3>
                                <p class="text-xs text-gray-500">Total target {{ formatAngka(grup.total) }}</p>
                            </div>
                            <button
                                type="button"
                                class="rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700"
                                @click="hapusTahun(grup.tahun)"
                            >
                                Hapus Tahun {{ grup.tahun }}
                            </button>
                        </div>

                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">{{ labelKelompok }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Jumlah Baris</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Total Target</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="b in grup.baris" :key="b.produk" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-800">{{ b.produk }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ b.jumlah_baris.toLocaleString('id-ID') }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.total_target) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="!perTahun.length"
                        class="rounded-lg bg-white p-6 text-center text-sm text-gray-400 shadow ring-1 ring-gray-100"
                    >
                        Belum ada data RKA.
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
