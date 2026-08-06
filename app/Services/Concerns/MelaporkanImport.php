<?php

namespace App\Services\Concerns;

use App\Exceptions\ImportException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Menjadikan validasi impor bersifat per-baris.
 *
 * Baris valid tetap diproses meskipun baris lain salah. Ringkasan dan detail
 * kesalahan dikembalikan melalui kunci `laporan` pada hasil importer.
 */
trait MelaporkanImport
{
    private int $totalBarisSumber = 0;

    /** @var list<array{baris:int|null,pesan:string,data:array<string,mixed>}> */
    private array $barisTidakValid = [];

    /**
     * @template T
     * @param  Collection<int, array<string, mixed>>  $baris
     * @param  callable(array<string,mixed>, int): T|null  $mapper
     * @return Collection<int, T>
     */
    protected function petakanBarisAman(Collection $baris, callable $mapper): Collection
    {
        $this->totalBarisSumber = $baris->count();
        $this->barisTidakValid = [];
        $valid = collect();

        foreach ($baris as $i => $row) {
            try {
                $hasil = $mapper($row, $i);

                if ($hasil !== null) {
                    $valid->push($hasil);
                } else {
                    $this->catatBarisTidakValid($row, $i + 2, 'Baris dilewati karena data tidak lengkap atau tidak ditemukan di master.');
                }
            } catch (ImportException $e) {
                $this->catatBarisTidakValid($row, $i + 2, $e->getMessage());
            } catch (Throwable $e) {
                $this->catatBarisTidakValid($row, $i + 2, $e->getMessage());
            }
        }

        return $valid->values();
    }

    /**
     * Catat baris yang sengaja dilewati, misalnya target kosong atau kode master
     * tidak ditemukan. Baris lain tetap lanjut.
     *
     * @param  array<string, mixed>  $data
     */
    protected function catatBarisTidakValid(array $data, ?int $baris, string $pesan): void
    {
        $pesan = preg_replace('/^Baris\s+\d+\s*:\s*/i', '', trim($pesan)) ?: trim($pesan);

        $this->barisTidakValid[] = [
            'baris' => $baris,
            'pesan' => $pesan,
            'data' => $data,
        ];
    }

    /**
     * Tambahkan invalid setelah tahap parsing, misalnya bentrok data yang sudah
     * ada. Total sumber tidak berubah.
     *
     * @param  array<string, mixed>  $data
     */
    protected function catatInvalidTambahan(array $data, string $pesan, ?int $baris = null): void
    {
        $this->catatBarisTidakValid($data, $baris, $pesan);
    }

    /**
     * @return array{total_baris:int,valid:int,tidak_valid:int,error:list<array{baris:int|null,pesan:string,data:array<string,mixed>}>}
     */
    protected function laporanImport(): array
    {
        $invalid = count($this->barisTidakValid);

        return [
            'total_baris' => $this->totalBarisSumber,
            'valid' => max(0, $this->totalBarisSumber - $invalid),
            'tidak_valid' => $invalid,
            'error' => $this->barisTidakValid,
        ];
    }
}
