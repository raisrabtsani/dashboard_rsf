import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk domain Laba (profitabilitas, BULANAN).
 *
 * DILARANG memanggil axios langsung di komponen. Filter periode memakai
 * tahun + bulan (bukan tanggal); lingkup data tetap ditentukan middleware
 * `scope` di backend.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.laba.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.laba.snapshot', filter);

/** Garis kumulatif YTD sepanjang bulan. */
export const fetchChart = (filter) => ambil('api.laba.chart', filter);

/** Batang laba per bulan (MTD), hasil selisih antar bulan kumulatif. */
export const fetchChartMtd = (filter) => ambil('api.laba.chart-mtd', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.laba.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.laba.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.laba.uker', { cabangId })).then((r) => r.data);
