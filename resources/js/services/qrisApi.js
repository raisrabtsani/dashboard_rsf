import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk sub-domain merchant QRIS.
 * Bentuknya identik dengan edcApi.js — hanya beda prefix route.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.merchant.qris.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.merchant.qris.snapshot', filter);

export const fetchChart = (filter) => ambil('api.merchant.qris.chart', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.merchant.qris.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.merchant.qris.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.merchant.qris.uker', { cabangId })).then((r) => r.data);
