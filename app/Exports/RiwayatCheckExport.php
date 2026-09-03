<?php

namespace App\Exports;

use App\Models\RiwayatCheck;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RiwayatCheckExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return RiwayatCheck::query()
            ->select([
                'code_document',
                'base_document',
                'created_at',
                'total_data',
                'total_price',
            ])
            ->orderBy('created_at', 'desc');
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

    public function map($row): array
    {
        static $no = 0;
        $no++;

        $fileName = $row->base_document;

        $url = asset('storage/ekspedisis/' . rawurlencode($fileName));

        return [
            $no,
            $row->code_document,
            $fileName,
            $row->created_at
                ? $row->created_at->format('Y-m-d H:i:s')
                : null,
            $row->total_data,
            $row->total_price,
            $url,
        ];
    }
}
