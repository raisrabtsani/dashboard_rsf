import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk domain Pinjaman (Kredit).
 * DILARANG memanggil axios langsung di komponen.
 *
 * `tab` (total|sml|npl) ikut dikirim di semua endpoint berdata, karena tab
 * menentukan kualitas mana yang dihitung backend.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.pinjaman.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.pinjaman.snapshot', filter);

export const fetchChart = (filter) => ambil('api.pinjaman.chart', filter);

/** Khusus Pinjaman: tren harian dipecah per segmen. */
export const fetchChartSegmen = (filter) => ambil('api.pinjaman.chart-segmen', filter);

/** Khusus Pinjaman: rincian per segmen dengan pecahan Lancar/SML/NPL. */
export const fetchProduk = (filter) => ambil('api.pinjaman.produk', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.pinjaman.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.pinjaman.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.pinjaman.uker', { cabangId })).then((r) => r.data);
