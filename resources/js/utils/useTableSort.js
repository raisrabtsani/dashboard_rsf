import { computed, ref } from 'vue';

/**
 * Sort tabel sisi-klien. Dipakai bersama komponen SortArrow.
 *
 * Dipakai ulang semua tabel "Kinerja Cabang" — jangan bikin varian per halaman.
 */
export function useTableSort(kolomAwal = null, arahAwal = 'desc') {
    const kolom = ref(kolomAwal);
    const arah = ref(arahAwal);

    function urutkanKolom(key) {
        if (kolom.value === key) {
            arah.value = arah.value === 'asc' ? 'desc' : 'asc';

            return;
        }

        kolom.value = key;
        // Kolom angka lebih berguna dibuka dari yang terbesar.
        arah.value = 'desc';
    }

    const arahUntuk = (key) => (kolom.value === key ? arah.value : null);

    function urutkan(baris) {
        if (!kolom.value) return baris;

        const faktor = arah.value === 'asc' ? 1 : -1;

        return [...baris].sort((a, b) => {
            const x = a[kolom.value];
            const y = b[kolom.value];

            // null selalu di bawah, apa pun arah sortnya.
            if (x === null || x === undefined) return 1;
            if (y === null || y === undefined) return -1;

            if (typeof x === 'string' || typeof y === 'string') {
                return String(x).localeCompare(String(y), 'id') * faktor;
            }

            return (x - y) * faktor;
        });
    }

    return { kolom, arah, urutkanKolom, arahUntuk, urutkan, terurut: (baris) => computed(() => urutkan(baris.value)) };
}
