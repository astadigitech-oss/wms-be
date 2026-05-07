<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BundlingSkuExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Old Barcode Bundle',
            'New Barcode Bundle',
            'Nama Produk Bundle',
            'Qty',
            'Category/Tag Color',
            'Original Price/Old Price',
            'Sale Price/New Price',
            'User'
        ];
    }

    public function map($row): array
    {
        $oldBarcode = $row->old_barcode_product ?? '-';
        $newBarcode = $row->new_barcode_product ?? '-';
        $nameBundle = $row->new_name_product ?? '-';
        $listQty = $row->new_quantity_product ?? 0;
        $categoryOrTag = $row->new_category_product ?? $row->new_tag_product ?? '-';

        return [
            $oldBarcode,
            $newBarcode,
            $nameBundle,
            $listQty,
            $categoryOrTag,
            $row->old_price_product,
            $row->new_price_product,
            $row->user->name ?? '-'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}