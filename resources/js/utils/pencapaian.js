/**
 * Kelas warna pencapaian terhadap target RKA.
 *
 * Ambang batas TIM (jangan diubah per halaman):
 *   >= 100%  hijau   — target tercapai
 *   95-100%  kuning  — mendekati
 *   < 95%    merah   — di bawah target
 *
 * null = tidak ada target -> netral, bukan merah. Tidak punya target berbeda
 * artinya dari gagal mencapai target.
 *
 * ARAH TERBALIK: sebagian KPI makin KECIL makin baik (SML, NPL, EDC SV Rp.0).
 * Untuk itu pakai varian *Inverse — ambang dan peta warnanya sengaja SATU
 * sumber di file ini supaya kedua arah tidak pernah berbeda diam-diam.
 */
export const AMBANG_TERCAPAI = 100;
export const AMBANG_MENDEKATI = 95;

/** Batas atas "masih wajar" untuk KPI arah terbalik. */
export const AMBANG_TOLERANSI_INVERSE = 105;

const TEKS = {
    baik: 'text-emerald-600',
    sedang: 'text-amber-500',
    buruk: 'text-rose-500',
    netral: 'text-gray-400',
};

const BADGE = {
    baik: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
    sedang: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
    buruk: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
    netral: 'bg-gray-50 text-gray-500 ring-1 ring-gray-200',
};

const kosong = (n) => n === null || n === undefined || Number.isNaN(n);

/** Tingkat untuk KPI normal: makin besar makin baik. */
function tingkat(persen) {
    if (kosong(persen)) return 'netral';
    if (persen >= AMBANG_TERCAPAI) return 'baik';
    if (persen >= AMBANG_MENDEKATI) return 'sedang';

    return 'buruk';
}

/** Tingkat untuk KPI terbalik: makin kecil makin baik (realisasi <= target). */
function tingkatInverse(persen) {
    if (kosong(persen)) return 'netral';
    if (persen <= AMBANG_TERCAPAI) return 'baik';
    if (persen <= AMBANG_TOLERANSI_INVERSE) return 'sedang';

    return 'buruk';
}

/** Pilih fungsi tingkat sesuai arah KPI. */
const nilaiTingkat = (persen, inverse) => (inverse ? tingkatInverse(persen) : tingkat(persen));

/** Warna teks pencapaian (makin besar makin baik). */
export const pctCls = (persen) => TEKS[tingkat(persen)];

/** Badge pencapaian (makin besar makin baik). */
export const pctBadgeCls = (persen) => BADGE[tingkat(persen)];

/** Warna teks untuk KPI arah terbalik — SML, NPL, EDC SV Rp.0. */
export const pctClsInverse = (persen) => TEKS[tingkatInverse(persen)];

/** Badge untuk KPI arah terbalik. */
export const pctBadgeClsInverse = (persen) => BADGE[tingkatInverse(persen)];

/**
 * Varian ber-flag, supaya halaman bertab (mis. Pinjaman Total/SML/NPL) tidak
 * perlu bercabang if/else di banyak tempat.
 */
export const pctClsArah = (persen, inverse = false) => TEKS[nilaiTingkat(persen, inverse)];
export const pctBadgeClsArah = (persen, inverse = false) => BADGE[nilaiTingkat(persen, inverse)];

/**
 * Warna untuk nilai delta.
 *
 * Normal: naik hijau, turun merah.
 * Inverse (SML/NPL): naik justru BURUK — kualitas kredit memburuk.
 */
export function deltaCls(nilai, inverse = false) {
    if (kosong(nilai) || nilai === 0) return TEKS.netral;

    const naik = nilai > 0;

    return (inverse ? !naik : naik) ? TEKS.baik : TEKS.buruk;
}
