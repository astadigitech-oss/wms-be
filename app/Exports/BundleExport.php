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
    protected $downloader;

    public function __construct($data, $downloader)
    {
        $this->data = $data;
        $this->downloader = $downloader;
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
            'Nama Bundle',
            'List Produk Bundle',
            'Qty',
            'Category/Tag Color',
            'Original Price',
            'Sale price',
            'User'
        ];
    }

    public function map($bundle): array
    {

        $oldBarcodeBundle = $bundle->old_barcode_bundle ?? '-';
        $newBarcodeBundle = $bundle->barcode_bundle ?? '-';

        $listProduk = '-';
        $user = '-';

        if ($bundle->product_bundles && $bundle->product_bundles->isNotEmpty()) {


            $listProduk = $bundle->product_bundles->map(function ($item) {
                $oldItemBarcode = $item->old_barcode_product ?? '-';
                $newItemBarcode = $item->new_barcode_product ?? '-';


                return "- " . $item->new_name_product . " [Old: " . $oldItemBarcode . " | New: " . $newItemBarcode . "]";
            })->implode("\n");

            $firstItem = $bundle->product_bundles->first();
            $user = $firstItem->user ? $firstItem->user->name : '-';
        }

        $category = $bundle->category ?? $bundle->name_color ?? '-';


        return [
            $oldBarcodeBundle,
            $newBarcodeBundle,
            $bundle->name_bundle ?? '-',
            $listProduk,
            $bundle->total_product_bundle ?? 0,
            $category,
            $bundle->total_price_bundle ?? 0,
            $bundle->total_price_custom_bundle ?? 0,
            $user
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],


            'D' => [
                'alignment' => [
                    'wrapText' => true,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
                ]
            ],


            'A:I' => [
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
                ]
            ]
        ];
    }
}
