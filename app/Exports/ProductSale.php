<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Exportable;

class ProductSale implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;
    protected $model;

    public function __construct($model)
    {
        $this->model = $model;
    }

    public function query()
    {
        return $this->model::latest();
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

    /**
     * Chunk size per read operation
     */
    public function chunkSize(): int
    {
        return 1000;
    }
}
