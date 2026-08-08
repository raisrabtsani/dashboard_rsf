<?php

namespace App\Services;

use App\Models\RkaEdc;
use Illuminate\Support\Collection;

/** Importer target RKA EDC. Logika umum di MerchantRkaImportService. */
class RkaEdcCsvImportService extends MerchantRkaImportService
{
    /** @var list<string> */
    private const KPI_RKA = ['SALES_VOLUME', 'EDC_PRODUKTIF', 'TID', 'MID'];

    private int $salesVolumeBarisSumber = 0;

    private int $salesVolumeKombinasi = 0;

    protected function modelClass(): string
    {
        return RkaEdc::class;
    }

    protected function serviceClass(): string
    {
        return EdcService::class;
    }

    /**
     * RKA EDC hanya menerima empat KPI target. Nilai target 0 tetap mengikuti
     * nama KPI sumber; Sales_Volume_Marginal selalu menjadi SALES_VOLUME.
     */
    protected function kanonikKpi(string $nama): ?string
    {
        $kode = EdcService::kanonikKpi($nama);

        return in_array($kode, self::KPI_RKA, true) ? $kode : null;
    }

    /** @return list<string> */
    protected function labelKpiValid(): array
    {
        return ['Sales Volume', 'Produktif', 'TID', 'MID'];
    }

    /**
     * RKA Sales Volume memakai SUMIF berdasarkan kombinasi:
     * id_uker + KPI Sales Volume + bulan + tahun.
     *
     * Baris KPI lain tetap mengikuti perilaku upsert sebelumnya: bila kuncinya
     * sama, baris terakhir menjadi nilai yang disimpan.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    protected function agregasiBaris(Collection $baris): Collection
    {
        $this->salesVolumeBarisSumber = $baris
            ->where('kpi', 'SALES_VOLUME')
            ->count();

        $hasil = $baris
            ->groupBy(fn (array $row) => implode('|', [
                $row['uker_id'],
                $row['kpi'],
                $row['tahun'],
                $row['bulan'],
            ]))
            ->map(function (Collection $kelompok): array {
                $barisAkhir = $kelompok->last();

                if ($barisAkhir['kpi'] === 'SALES_VOLUME') {
                    $barisAkhir['target'] = (float) $kelompok->sum(
                        fn (array $row) => (float) $row['target'],
                    );
                }

                return $barisAkhir;
            })
            ->values();

        $this->salesVolumeKombinasi = $hasil
            ->where('kpi', 'SALES_VOLUME')
            ->count();

        return $hasil;
    }

    /** @return array<string, int> */
    protected function metadataAgregasi(): array
    {
        return [
            'sales_volume_baris_sumber' => $this->salesVolumeBarisSumber,
            'sales_volume_kombinasi' => $this->salesVolumeKombinasi,
            'sales_volume_baris_digabung' => max(
                0,
                $this->salesVolumeBarisSumber - $this->salesVolumeKombinasi,
            ),
        ];
    }
}
