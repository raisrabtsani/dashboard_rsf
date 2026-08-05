import axios from 'axios';

/** Satu-satunya tempat axios untuk DPK Hourly. */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.simpanan-hourly.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.simpanan-hourly.snapshot', filter);

export const fetchChart = (filter) => ambil('api.simpanan-hourly.chart', filter);

export const fetchBranch = (filter) => ambil('api.simpanan-hourly.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.simpanan-hourly.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.simpanan-hourly.uker', { cabangId })).then((r) => r.data);
