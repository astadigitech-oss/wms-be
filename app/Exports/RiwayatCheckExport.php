<?php

namespace App\Exports;

use App\Models\RiwayatCheck;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class RiwayatCheckExport implements FromArray, WithHeadings
{
    protected array $rows = [];

    public function __construct()
    {
        $histories = RiwayatCheck::query()
            ->select([
                'code_document',
                'base_document',
                'created_at',
                'total_data',
                'total_price',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $no = 1;

        foreach ($histories as $history) {

            // Nama file unik
            $fileName = 'riwayat-'
                . $history->code_document
                . '-'
                . now()->format('Ymd_His_u')
                . '-'
                . uniqid()
                . '.xlsx';

            $path = 'ekspedisis/' . $fileName;

            // Buat file Excel detail
            Excel::store(
                new class($history) implements FromArray, WithHeadings {

                    private $history;

                    public function __construct($history)
                    {
                        $this->history = $history;
                    }

                    public function headings(): array
                    {
                        return [
                            'Kode File',
                            'Nama File',
                            'Tanggal',
                            'Total Data',
                            'Total Harga',
                        ];
                    }

                    public function array(): array
                    {
                        return [[
                            $this->history->code_document,
                            $this->history->base_document,
                            $this->history->created_at
                                ? $this->history->created_at->format('Y-m-d H:i:s')
                                : null,
                            $this->history->total_data,
                            $this->history->total_price,
                        ]];
                    }
                },
                $path,
                'public'
            );

            $this->rows[] = [
                $no++,
                $history->code_document,
                $history->base_document,
                $history->created_at
                    ? $history->created_at->format('Y-m-d H:i:s')
                    : null,
                $history->total_data,
                $history->total_price,
                asset('storage/' . $path),
            ];
        }
    }

    public function headings(): array
    {
        return [
            'No',
            'Kode File',
            'Nama File',
            'Tanggal',
            'Total Data',
            'Total Harga',
            'Link Download',
        ];
    }

    public function array(): array
    {
        return $this->rows;
    }
}
