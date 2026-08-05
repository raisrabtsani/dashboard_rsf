/**
 * Format angka uang untuk tampilan.
 *
 * KONTRAK: semua fungsi di sini menerima nilai dalam satuan JUTA — backend sudah
 * mengonversinya lewat App\Support\Satuan::toJuta(). Jangan mengirim rupiah
 * penuh ke sini, dan jangan membagi 1.000.000 di komponen.
 *
 * 1 M (miliar) = 1.000 juta · 1 T (triliun) = 1.000.000 juta
 */

const RIBU = 1_000;
const JUTA = 1_000_000;

const angka = (nilai, desimal) =>
    new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: desimal,
        maximumFractionDigits: desimal,
    }).format(nilai);

/**
 * Skalakan nilai (dalam juta) ke satuan terbesar yang masih terbaca.
 */
export function skalaAngka(juta) {
    const abs = Math.abs(juta);

    if (abs >= JUTA) return { nilai: juta / JUTA, satuan: 'T', desimal: 2 };
    if (abs >= RIBU) return { nilai: juta / RIBU, satuan: 'M', desimal: 2 };

    return { nilai: juta, satuan: 'Jt', desimal: abs >= 100 ? 0 : 1 };
}

/**
 * "1,23 T" / "456,78 M" / "12,3 Jt".
 *
 * null / undefined berarti TIDAK ADA DATA (bukan nol) dan ditampilkan "–".
 */
export function formatAngka(juta, { satuan = true } = {}) {
    if (juta === null || juta === undefined || Number.isNaN(juta)) return '–';

    const skala = skalaAngka(juta);
    const teks = angka(skala.nilai, skala.desimal);

    return satuan ? `${teks} ${skala.satuan}` : teks;
}

/**
 * Sama seperti formatAngka tapi selalu bertanda: "+1,2 M" / "−340,5 Jt".
 */
export function formatDelta(juta) {
    if (juta === null || juta === undefined || Number.isNaN(juta)) return '–';

    const tanda = juta > 0 ? '+' : juta < 0 ? '−' : '';

    return `${tanda}${formatAngka(Math.abs(juta))}`;
}

/**
 * "97,45%" — persen sudah dalam skala 0-100 dari backend.
 */
export function formatPct(persen, desimal = 2) {
    if (persen === null || persen === undefined || Number.isNaN(persen)) return '–';

    return `${angka(persen, desimal)}%`;
}

/**
 * Persen bertanda untuk baris delta: "+3,21%".
 */
export function formatDeltaPct(persen) {
    if (persen === null || persen === undefined || Number.isNaN(persen)) return '–';

    const tanda = persen > 0 ? '+' : persen < 0 ? '−' : '';

    return `${tanda}${formatPct(Math.abs(persen))}`;
}
