<script setup>
/**
 * Logo RSF — Region 7 Jakarta 2.
 *
 * Digambar sebagai SVG (bukan berkas gambar) supaya tajam di segala ukuran dan
 * mengikuti currentColor. Dipakai di login, sidebar, dan layar loading.
 */
</script>

<template>
    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="RSF">
        <!-- bingkai kartu -->
        <rect x="6" y="7" width="24" height="30" rx="4" stroke="currentColor" stroke-width="3" />
        <!-- batang grafik naik -->
        <rect x="11" y="24" width="3.4" height="8" rx="1" fill="currentColor" />
        <rect x="16.5" y="20" width="3.4" height="12" rx="1" fill="currentColor" />
        <rect x="22" y="15" width="3.4" height="17" rx="1" fill="currentColor" />
        <!-- panah tren -->
        <path
            d="M11 26 L18 20 L23 22 L29 13"
            stroke="currentColor"
            stroke-width="2.6"
            stroke-linecap="round"
            stroke-linejoin="round"
            fill="none"
        />
        <path d="M25 12.5 L30 12 L29.2 17 Z" fill="currentColor" />
    </svg>
</template>
