<?php

namespace App\Exports;

use App\Models\RiwayatCheck;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RiwayatCheckExport implements FromArray, WithHeadings
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
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
        return $this->data;
    }
}
