import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Level akses user untuk menyembunyikan filter yang tak relevan.
 *
 * KOSMETIK SAJA. Penegakan lingkup data ada di middleware EnforceUserScope —
 * backend menulis ulang area_id/cabang_id/uker_id apa pun yang dikirim klien.
 * Jangan pernah menjadikan composable ini satu-satunya pengaman.
 */
export function useScope() {
    const user = computed(() => usePage().props.auth?.user ?? null);
    const level = computed(() => user.value?.access_level ?? 'LEVEL_UKER');

    const semuaData = computed(() => level.value === 'LEVEL_ALL');
    const terkunciCabang = computed(() => level.value === 'LEVEL_CABANG');
    const terkunciUker = computed(() => level.value === 'LEVEL_UKER');

    return {
        user,
        level,
        semuaData,
        terkunciCabang,
        terkunciUker,
        cabangId: computed(() => user.value?.cabang_id ?? null),
        ukerId: computed(() => user.value?.uker_id ?? null),
        // Filter yang boleh diubah user.
        bolehPilihArea: semuaData,
        bolehPilihCabang: semuaData,
        bolehPilihUker: computed(() => !terkunciUker.value),
        // Tabel ranking antar entitas kosong untuk level uker.
        bolehLihatRanking: computed(() => !terkunciUker.value),
    };
}
