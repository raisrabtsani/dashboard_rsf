<script setup>
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { Bar } from 'vue-chartjs';
import { formatAngka } from '@/utils/formatAngka';
import { computed } from 'vue';

/**
 * Pembungkus bar chart baku. Dipakai domain Laba untuk laba per bulan (MTD).
 * Bar dengan nilai null tampil sebagai celah kosong (bukan 0).
 */
ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const props = defineProps({
    labels: { type: Array, required: true },
    datasets: { type: Array, required: true },
});

const data = computed(() => ({ labels: props.labels, datasets: props.datasets }));

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
        tooltip: {
            callbacks: {
                label: (ctx) => `${ctx.dataset.label}: ${formatAngka(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        y: {
            ticks: { callback: (v) => formatAngka(v) },
            grid: { color: 'rgba(0,0,0,0.05)' },
        },
        x: { grid: { display: false } },
    },
};
</script>

<template>
    <Bar :data="data" :options="options" />
</template>
