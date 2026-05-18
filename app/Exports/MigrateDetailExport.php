<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class MigrateDetailExport implements WithMultipleSheets
{
    use Exportable;

    protected $migrate;

    public function __construct($migrate)
    {
        $this->migrate = $migrate;
    }

    public function sheets(): array
    {
        $allProductsData = [];
        $racksData = [];

        // Parsing Destinasi Toko
        $destinationName = $this->migrate->destiny_document_migrate;
        $destObj = \App\Models\Destination::find($this->migrate->destiny_document_migrate);
        if ($destObj) {
            $destinationName = $destObj->shop_name;
        }

        // Kumpulkan Semua Data
        if ($this->migrate->migrates->isNotEmpty()) {
            foreach ($this->migrate->migrates as $migrateItem) {
                $rack = $migrateItem->colorRack;
                $rackName = $rack ? $rack->name : 'Rak Dihapus';
                $rackBarcode = $rack ? $rack->barcode : '-';

                $rackItemsCount = 0;
                $rackSumOldPrice = 0;
                $rackSumNewPrice = 0;

                if ($rack && $rack->colorRackProducts->isNotEmpty()) {
                    foreach ($rack->colorRackProducts as $cp) {
                        $type        = '-';
                        $oldBarcode  = '-';
                        $newBarcode  = '-';
                        $newName     = '-';
                        $newQty      = 0;
                        $oldPrice    = 0;
                        $newPrice    = 0;
                        $status      = '-';
                        $colorName   = 'Product Color';

                        if ($cp->bundle_id && $cp->bundle) {
                            $type        = 'Bundle';
                            $oldBarcode  = '-';
                            $newBarcode  = $cp->bundle->barcode_bundle;
                            $newName     = '[BUNDLE] ' . $cp->bundle->name_bundle;
                            $newQty      = 1;
                            $oldPrice    = $cp->bundle->total_price_bundle;
                            $newPrice    = $cp->bundle->total_price_custom_bundle;
                            $status      = $cp->bundle->product_status;
                            $colorName   = $cp->bundle->name_color ?? 'Product Color';
                        } elseif ($cp->new_product_id && $cp->newProduct) {
                            $type        = 'Produk';
                            $oldBarcode  = $cp->newProduct->old_barcode_product ?? '-';
                            $newBarcode  = $cp->newProduct->new_barcode_product ?? '-';
                            $newName     = $cp->newProduct->new_name_product ?? '-';
                            $newQty      = $cp->newProduct->new_qty_product ?? $cp->newProduct->new_quantity_product ?? 1;
                            $oldPrice    = $cp->newProduct->old_price_eq ?? $cp->newProduct->old_price_product ?? 0;
                            $newPrice    = $cp->newProduct->new_price_product ?? $cp->newProduct->new_price_eq ?? 0;
                            $status      = $cp->newProduct->new_status_product;

                            $colorName   = $cp->newProduct->new_tag_product ?? 'Product Color';
                        } else {
                            continue;
                        }

                        $allProductsData[] = [
                            'color_rack'   => $rackName,
                            'color_name'   => $colorName,
                            'rack_barcode' => $rackBarcode,
                            'type'         => $type,
                            'old_barcode'  => $oldBarcode,
                            'new_barcode'  => $newBarcode,
                            'new_name'     => $newName,
                            'new_qty'      => $newQty,
                            'old_price'    => $oldPrice,
                            'new_price'    => $newPrice,
                            'status'       => strtoupper($status),
                        ];

                        $rackItemsCount++;
                        $rackSumOldPrice += $oldPrice;
                        $rackSumNewPrice += $newPrice;
                    }
                }

                $racksData[] = [
                    'rack_name'     => $rackName,
                    'rack_barcode'  => $rackBarcode,
                    'total_items'   => $rackItemsCount,
                    'sum_old_price' => $rackSumOldPrice,
                    'sum_new_price' => $rackSumNewPrice,
                ];
            }
        }

        return [
            new MigrateProductListSheet($this->migrate, $destinationName, $allProductsData),
            new MigrateSummaryColorSheet($allProductsData),
            new MigrateSummaryRackSheet($racksData),
        ];
    }
}

class MigrateProductListSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $migrate;
    protected $destinationName;
    protected $data;

    public function __construct($migrate, $destinationName, $data)
    {
        $this->migrate = $migrate;
        $this->destinationName = $destinationName;
        $this->data = $data;
    }

    public function collection()
    {
        $output = collect();

        $actualTotalProduct = count($this->data);

        $output->push(['Informasi Dokumen Migrasi', '', '', '', '', '', '', '']);
        $output->push(['ID Dokumen:', $this->migrate->id, '', '', '', '', '', '']);
        $output->push(['Kode Dokumen:', $this->migrate->code_document_migrate, '', '', '', '', '', '']);
        $output->push(['Destinasi (Toko):', $this->destinationName, '', '', '', '', '', '']);
        $output->push(['Total Produk:', $actualTotalProduct, '', '', '', '', '', '']);
        $output->push(['Status:', strtoupper($this->migrate->status_document_migrate), '', '', '', '', '', '']);
        $output->push(['']);

        $output->push([
            'Nama Rak Color',       
            'Barcode Rak',          
            'Tipe Item',            
            'Old Barcode Product',  
            'New Barcode Product',  
            'New Name Product',     
            'New QTY Product',      
            'New Price Product',    
        ]);

        // Isi Data Produk
        foreach ($this->data as $row) {
            $output->push([
                $row['color_rack'],
                $row['rack_barcode'],
                $row['type'],
                $row['old_barcode'],
                $row['new_barcode'],
                $row['new_name'],
                $row['new_qty'],
                $row['new_price'],
            ]);
        }

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->getStyle('A8:H8')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        if ($lastRow > 8) {
            $sheet->getStyle("A9:H{$lastRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);

            $sheet->getStyle("H9:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

            $sheet->getStyle("G9:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        return [];
    }

    public function title(): string
    {
        return 'List Product';
    }
}

class MigrateSummaryColorSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        $output = collect();

        $output->push(['Nama Color', 'Jumlah Produk', 'Total Harga Baru (New Price)']);

        $grouped = collect($this->data)->groupBy('color_name');

        $grandTotalCount = 0;
        $grandTotalPrice = 0;

        foreach ($grouped as $color => $items) {
            $count = count($items);
            $sumNewPrice = collect($items)->sum('new_price');

            $grandTotalCount += $count;
            $grandTotalPrice += $sumNewPrice;

            $output->push([$color, $count, $sumNewPrice]);
        }

        $output->push(['Grand Total', $grandTotalCount, $grandTotalPrice]);

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Header Styling
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Content Borders
        $sheet->getStyle("A2:C{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        if ($lastRow > 1) {
            $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }

        // Style baris Grand Total
        $sheet->getStyle("A{$lastRow}:C{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
        ]);

        return [];
    }

    public function title(): string
    {
        return 'Summary Color';
    }
}

class MigrateSummaryRackSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $racksData;

    public function __construct($racksData)
    {
        $this->racksData = $racksData;
    }

    public function collection()
    {
        $output = collect();
        $output->push(['NO', 'Nama Rak', 'Barcode Rak', 'Total Item di Rak', 'Sum Harga Awal', 'Sum Harga POS (Actual)']);

        $no = 1;
        $grandTotalCount = 0;
        $grandTotalOldPrice = 0;
        $grandTotalNewPrice = 0;

        foreach ($this->racksData as $rack) {
            $grandTotalCount += $rack['total_items'];
            $grandTotalOldPrice += $rack['sum_old_price'];
            $grandTotalNewPrice += $rack['sum_new_price'];

            $output->push([
                $no++,
                $rack['rack_name'],
                $rack['rack_barcode'],
                $rack['total_items'],
                $rack['sum_old_price'],
                $rack['sum_new_price']
            ]);
        }

        $output->push([
            'Grand Total',
            '',
            '',
            $grandTotalCount,
            $grandTotalOldPrice,
            $grandTotalNewPrice
        ]);

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $sheet->getStyle("A2:F{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $sheet->mergeCells("A{$lastRow}:C{$lastRow}");
        $sheet->getStyle("A{$lastRow}:F{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
        ]);

        $sheet->getStyle("A{$lastRow}:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        if ($lastRow > 1) {
            $sheet->getStyle("E2:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("D2:D{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        return [];
    }

    public function title(): string
    {
        return 'Summary Rack';
    }
}
