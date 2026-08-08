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

const labelTahunPendek = (tahun) => `'${String(tahun).slice(-2)}`;

const garisAkumulasi = (warna, label, data) => ({
    type: 'line',
    label,
    data,
    borderColor: warna,
    backgroundColor: warna,
    borderWidth: 2,
    tension: 0.25,
    pointRadius: 3,
    pointHoverRadius: 4,
    spanGaps: false,
    order: 0,
    datalabels: {
        align: 'top',
        anchor: 'end',
        offset: 3,
        color: warna,
        backgroundColor: 'rgba(255,255,255,0.95)',
        borderColor: warna,
        borderWidth: 1,
        borderRadius: 3,
        padding: { top: 2, right: 3, bottom: 2, left: 3 },
        // Label akumulasi Januari menimpa label batang karena nilainya sama.
        display: (ctx) => ctx.dataIndex !== 0 && ctx.dataset.data[ctx.dataIndex] !== null,
        formatter: (v) => formatAngka(v),
        font: { size: 8, weight: '700' },
    },
});

const batang = (warna, label, data) => ({
    type: 'bar',
    label,
    data,
    backgroundColor: warna,
    borderColor: warna,
    borderWidth: 0,
    borderRadius: 3,
    borderSkipped: false,
    maxBarThickness: 48,
    order: 1,
    datalabels: {
        align: 'end',
        anchor: 'end',
        offset: 2,
        color: warna,
        backgroundColor: 'rgba(255,255,255,0.95)',
        borderColor: warna,
        borderWidth: 1,
        borderRadius: 3,
        padding: { top: 2, right: 3, bottom: 2, left: 3 },
        display: (ctx) => ctx.dataset.data[ctx.dataIndex] !== null,
        formatter: (v) => formatAngka(v),
        font: { size: 8, weight: '700' },
    },
});

const data = computed(() => ({
    labels: props.labels,
    datasets: [
        garisAkumulasi(MENTARI, `Akum ${labelTahunPendek(props.tahunLalu.tahun)}`, props.tahunLalu.akumulasi),
        garisAkumulasi(NUSANTARA, `Akum ${labelTahunPendek(props.tahunIni.tahun)}`, props.tahunIni.akumulasi),
        batang(MENTARI, `Delta ${labelTahunPendek(props.tahunLalu.tahun)}`, props.tahunLalu.bulanan),
        batang(NUSANTARA, `Delta ${labelTahunPendek(props.tahunIni.tahun)}`, props.tahunIni.bulanan),
    ],
}));

const options = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    layout: { padding: { top: 18, left: 2, right: 2, bottom: 0 } },
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                boxWidth: 10,
                boxHeight: 10,
                usePointStyle: true,
                pointStyle: 'rect',
                padding: 14,
                color: '#64748b',
                font: { size: 9, weight: '600' },
            },
        },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    `${ctx.dataset.label}: ${ctx.parsed.y === null ? '–' : formatAngka(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        y: {
            border: { display: false },
            ticks: {
                callback: (v) => formatAngka(v),
                color: '#94a3b8',
                font: { size: 9 },
                maxTicksLimit: 6,
            },
            grid: { color: 'rgba(148,163,184,0.20)', drawTicks: false },
        },
        x: {
            border: { color: 'rgba(148,163,184,0.24)' },
            ticks: { color: '#94a3b8', font: { size: 9 } },
            grid: { display: false },
        },
    },
    datasets: {
        bar: {
            categoryPercentage: 0.7,
            barPercentage: 0.84,
        },
    },
}));
</script>

<template>
    <Bar :data="data" :options="options" />
</template>
