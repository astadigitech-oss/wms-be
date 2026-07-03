<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Events\AfterSheet;

class ProductSaleMonth implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithEvents
{
    use Exportable;

    protected $model;
    protected $month;
    protected $totalPrice = 0;

    public function __construct($model, $month)
    {
        $this->model = $model;
        $this->month = $month;
    }

    public function query()
    {
        // Query untuk mendapatkan data produk dengan total harga penjualan
        return $this->model::select('sales.*')
            ->selectRaw('(SELECT SUM(product_price_sale) FROM sales AS s WHERE s.status_sale = "selesai" AND MONTH(s.created_at) = ?) AS total_price_sum', [$this->month])
            ->where('status_sale', 'selesai')
            ->whereMonth('created_at', $this->month);
    }



    public function headings(): array
    {
        return [
            'Code Document Sale',
            'Name Product',
            'Category Product',
            'Barcode Product',
            'Old Price Product',
            'New Price Product',
            'New Quantity Product',
            'Status Product',
            'Discount Sale',
            'New Discount',
            'Display Price',
            'Code Document',
        ];
    }

    public function map($product): array
    {
        // Tambahkan harga ke total
        $this->totalPrice = $product->total_price_sum;

        return [
            $product->code_document_sale,
            $product->product_name_sale,
            $product->product_category_sale,
            $product->product_barcode_sale,
            $product->product_old_price_sale,
            $product->product_price_sale,
            $product->product_qty_sale,
            $product->status_sale,
            $product->total_discount_sale,
            $product->new_discount,
            $product->display_price,
            $product->code_document,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                // Baris terakhir setelah data
                $lastRow = $event->sheet->getHighestRow() + 1;
    
                // Tampilkan label dan total
                $event->sheet->setCellValue("L{$lastRow}", 'Total:');
                $event->sheet->setCellValue("M{$lastRow}", number_format($this->totalPrice, 2, ',', '.'));

            },
        ];
    }
}
