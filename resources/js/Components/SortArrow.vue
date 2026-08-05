<script setup>
/**
 * Indikator arah sort pada header tabel. Dipasangkan dengan useTableSort().
 *
 * `arah` = 'asc' | 'desc' | null (null = kolom ini sedang tidak dipakai sort).
 */
defineProps({
    arah: { type: String, default: null },
});
</script>

<template>
    <span class="ms-1 inline-flex flex-col leading-none" aria-hidden="true">
        <svg
            class="h-2 w-2"
            :class="arah === 'asc' ? 'text-brand-600' : 'text-gray-300'"
            viewBox="0 0 8 5"
            fill="currentColor"
        >
            <path d="M4 0l4 5H0z" />
        </svg>
        <svg
            class="mt-px h-2 w-2"
            :class="arah === 'desc' ? 'text-brand-600' : 'text-gray-300'"
            viewBox="0 0 8 5"
            fill="currentColor"
        >
            <path d="M4 5L0 0h8z" />
        </svg>
    </span>
</template>
