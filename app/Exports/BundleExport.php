<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BundleExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $data;
    protected $user;

    public function __construct($data, $user)
    {
        $this->data = $data;
        $this->user = $user;
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Barcode Bundle (Old / New)',
            'Nama Bundle',
            'List Produk Bundle',
            'Qty',
            'Category',
            'Original Price',
            'Sale price',
            'User'
        ];
    }

    public function map($bundle): array
    {
        $barcode = ($bundle->old_barcode_bundle ?? '-') . ' / ' . ($bundle->barcode_bundle ?? '-');

        $listProduk = '-';
        if ($bundle->product_bundles && $bundle->product_bundles->isNotEmpty()) {
            $listProduk = $bundle->product_bundles->map(function ($item) {
                return "- " . $item->new_name_product;
            })->implode("\n");
        }

        $category = $bundle->category ?? $bundle->name_color ?? '-';

        return [
            $barcode,
            $bundle->name_bundle ?? '-',
            $listProduk,
            $bundle->total_product_bundle ?? 0,
            $category,
            $bundle->total_price_bundle ?? 0,
            $bundle->total_price_custom_bundle ?? 0,
            $this->user->name ?? 'System'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
            
            'C' => [
                'alignment' => [
                    'wrapText' => true,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
                ]
            ],
            
            'A:H' => [
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
                ]
            ]
        ];
    }
}