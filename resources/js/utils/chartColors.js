/**
 * Palet warna per bulan untuk line chart tren harian.
 *
 * Cermin dari konstanta bulan di backend. Dipakai semua domain yang menampilkan
 * tren harian per bulan — jangan bikin palet sendiri per halaman.
 */
export const BULAN = [
    { bulan: 1, nama: 'Jan', warna: '#0857C3' },
    { bulan: 2, nama: 'Feb', warna: '#71C5E8' },
    { bulan: 3, nama: 'Mar', warna: '#00A0DF' },
    { bulan: 4, nama: 'Apr', warna: '#F5A623' },
    { bulan: 5, nama: 'Mei', warna: '#7ED321' },
    { bulan: 6, nama: 'Jun', warna: '#BD10E0' },
    { bulan: 7, nama: 'Jul', warna: '#E0217B' },
    { bulan: 8, nama: 'Ags', warna: '#F8462C' },
    { bulan: 9, nama: 'Sep', warna: '#12B886' },
    { bulan: 10, nama: 'Okt', warna: '#845EF7' },
    { bulan: 11, nama: 'Nov', warna: '#FAB005' },
    { bulan: 12, nama: 'Des', warna: '#3C3C3C' },
];

export const warnaBulan = (bulan) =>
    BULAN.find((b) => b.bulan === bulan)?.warna ?? '#9CA3AF';
