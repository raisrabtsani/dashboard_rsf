<script setup>
import { computed } from 'vue';

const props = defineProps({
    laporan: { type: Object, default: null },
    namaBerkas: { type: String, default: 'hasil-import' },
    previewOnly: { type: Boolean, default: false },
});

const total = computed(() => Number(props.laporan?.total_baris ?? 0));
const valid = computed(() => Number(props.laporan?.valid ?? 0));
const invalid = computed(() => Number(props.laporan?.tidak_valid ?? 0));
const errors = computed(() => Array.isArray(props.laporan?.error) ? props.laporan.error : []);

function csvCell(value) {
    const text = String(value ?? '');
    return `"${text.replaceAll('"', '""')}"`;
}

function downloadError() {
    if (!errors.value.length) return;

    const semuaKolom = [...new Set(errors.value.flatMap((item) => Object.keys(item?.data ?? {})))];
    const header = ['Baris', 'Error', ...semuaKolom];
    const rows = errors.value.map((item) => [
        item?.baris ?? '',
        item?.pesan ?? 'Data tidak valid',
        ...semuaKolom.map((kolom) => item?.data?.[kolom] ?? ''),
    ]);

    const csv = '\uFEFF' + [header, ...rows]
        .map((row) => row.map(csvCell).join(','))
        .join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const dasar = String(props.namaBerkas || 'hasil-import').replace(/\.[^.]+$/, '').replace(/[^a-z0-9_-]+/gi, '-');

    link.href = url;
    link.download = `${dasar}-error.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <section v-if="laporan" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_12px_34px_rgba(15,23,42,0.07)]">
        <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Hasil Validasi Upload</p>
                <h3 class="mt-1 text-lg font-bold text-slate-900">Ringkasan Berkas</h3>
                <p class="mt-1 text-xs text-slate-500">
                    {{ previewOnly
                        ? 'Belum ada data yang disimpan. Periksa hasil validasi, unduh Error bila perlu, lalu tekan Upload Data Valid.'
                        : 'Semua baris valid sudah diproses. Baris tidak valid tidak menghentikan upload.' }}
                </p>
            </div>
            <button
                v-if="invalid > 0"
                type="button"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-rose-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700"
                @click="downloadError"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2h2v8l3-3 1.4 1.4L10 13.8 4.6 8.4 6 7l3 3V2z"/><path d="M3 15h14v2H3z"/></svg>
                Download Error ({{ invalid.toLocaleString('id-ID') }})
            </button>
        </div>

        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-3 sm:p-6">
            <div class="rounded-2xl bg-blue-50 p-4 ring-1 ring-blue-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-blue-600">Total Baris</p>
                <p class="mt-1 text-3xl font-extrabold tabular-nums text-blue-800">{{ total.toLocaleString('id-ID') }}</p>
            </div>
            <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-600">Valid</p>
                <p class="mt-1 text-3xl font-extrabold tabular-nums text-emerald-700">{{ valid.toLocaleString('id-ID') }}</p>
            </div>
            <div class="rounded-2xl bg-rose-50 p-4 ring-1 ring-rose-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-rose-600">Tidak Valid</p>
                <p class="mt-1 text-3xl font-extrabold tabular-nums text-rose-700">{{ invalid.toLocaleString('id-ID') }}</p>
            </div>
        </div>
    </section>
</template>
