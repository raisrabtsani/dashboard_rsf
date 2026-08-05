import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk sub-domain merchant EDC.
 *
 * DILARANG memanggil axios langsung di komponen. Endpoint chart &
 * branch-pencapaian menerima `kpi` (kode katalog) di dalam filter.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.merchant.edc.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.merchant.edc.snapshot', filter);

export const fetchChart = (filter) => ambil('api.merchant.edc.chart', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.merchant.edc.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.merchant.edc.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.merchant.edc.uker', { cabangId })).then((r) => r.data);
