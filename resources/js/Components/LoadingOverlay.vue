<script setup>
/**
 * Overlay loading di atas satu blok konten (kartu / chart / tabel).
 *
 * Pembungkusnya wajib `relative`. Pakai `senyap` untuk layar monitoring yang
 * auto-refresh (mis. DPK Hourly di videotron) supaya tidak berkedip tiap tarik data.
 */
defineProps({
    show: { type: Boolean, default: false },
    senyap: { type: Boolean, default: false },
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-150"
        leave-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div
            v-if="show && !senyap"
            class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-white/60 backdrop-blur-[1px]"
            role="status"
            aria-live="polite"
        >
            <svg class="h-6 w-6 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <span class="sr-only">Memuat data…</span>
        </div>
    </Transition>
</template>
