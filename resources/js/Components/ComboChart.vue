<script setup>
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import ChartDataLabels from 'chartjs-plugin-datalabels';
import { Bar } from 'vue-chartjs';
import { formatAngka } from '@/utils/formatAngka';
import { computed } from 'vue';

/**
 * Chart combo dua tahun: BATANG = nilai per bulan, GARIS = akumulasi berjalan.
 *
 * Dipakai PH & Net DG. Warna mengikuti konvensi dua tahun:
 *   Mentari  #71C5E8 = tahun lalu
 *   Nusantara #0857C3 = tahun berjalan
 *
 * Bulan tanpa data dikirim sebagai null (bukan 0) sehingga batangnya kosong dan
 * garisnya terputus — bukan jatuh ke sumbu nol.
 */
ChartJS.register(
    CategoryScale, LinearScale, BarElement, LineController, LineElement, PointElement,
    Tooltip, Legend, ChartDataLabels,
);

const MENTARI = '#71C5E8';
const NUSANTARA = '#0857C3';

const props = defineProps({
    /** ['Jan','Feb',...] sudah dipotong sampai bulan posisi. */
    labels: { type: Array, required: true },
    /** { tahun, bulanan: [], akumulasi: [] } */
    tahunLalu: { type: Object, required: true },
    tahunIni: { type: Object, required: true },
});

const garisAkumulasi = (warna, label, data) => ({
    type: 'line',
    label,
    data,
    borderColor: warna,
    backgroundColor: warna,
    borderWidth: 2,
    tension: 0.3,
    pointRadius: 3,
    spanGaps: false,
    datalabels: {
        align: 'top',
        anchor: 'end',
        color: warna,
        // Label akumulasi Januari PASTI menimpa label batangnya (nilainya sama
        // persis di bulan pertama), jadi disembunyikan.
        display: (ctx) => ctx.dataIndex !== 0 && ctx.dataset.data[ctx.dataIndex] !== null,
        formatter: (v) => formatAngka(v),
        font: { size: 10, weight: '600' },
    },
});

const batang = (warna, label, data) => ({
    type: 'bar',
    label,
    data,
    backgroundColor: warna,
    borderRadius: 3,
    datalabels: {
        align: 'end',
        anchor: 'end',
        color: warna,
        display: (ctx) => ctx.dataset.data[ctx.dataIndex] !== null,
        formatter: (v) => formatAngka(v),
        font: { size: 9 },
    },
});

const data = computed(() => ({
    labels: props.labels,
    datasets: [
        batang(MENTARI, `${props.tahunLalu.tahun}`, props.tahunLalu.bulanan),
        batang(NUSANTARA, `${props.tahunIni.tahun}`, props.tahunIni.bulanan),
        garisAkumulasi(MENTARI, `Akum ${props.tahunLalu.tahun}`, props.tahunLalu.akumulasi),
        garisAkumulasi(NUSANTARA, `Akum ${props.tahunIni.tahun}`, props.tahunIni.akumulasi),
    ],
}));

const options = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    layout: { padding: { top: 18 } },
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    `${ctx.dataset.label}: ${ctx.parsed.y === null ? '–' : formatAngka(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        y: { ticks: { callback: (v) => formatAngka(v) }, grid: { color: 'rgba(0,0,0,0.05)' } },
        x: { grid: { display: false } },
    },
}));
</script>

<template>
    <Bar :data="data" :options="options" />
</template>
