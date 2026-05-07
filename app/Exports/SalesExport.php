<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SalesExport implements FromCollection, WithHeadings, WithMapping
{
    protected $sales;

    public function __construct($sales)
    {
        $this->sales = $sales;
    }

    public function collection()
    {
        return $this->sales;
    }

    public function headings(): array
    {
        return [
            'Code Document Sale',
            'Product Name Sale',
            'Product Category Sale',
            'Product Barcode Sale',
            'Product Old Price Sale',
            'Product Price Sale',
            'Gabor Sale',
            'Product Update Price Sale',
            'Product Quantity Sale',
            'Total Discount Sale',
            'New Discount Sale',
            'Type Discount',
            'Display Price',
            'Code Document',
            'Old Barcode Product',
            'Actual Product Old Price Sale'
        ];
    }

    public function map($sale): array
    {
        return [
            $sale->code_document_sale,
            $sale->product_name_sale,
            $sale->product_category_sale,
            $sale->product_barcode_sale,
            $sale->product_old_price_sale,
            $sale->product_price_sale,
            $sale->gabor_sale,
            $sale->product_update_price_sale,
            $sale->product_qty_sale,
            $sale->total_discount_sale,
            $sale->new_discount_sale,
            $sale->type_discount,
            $sale->display_price,
            $sale->code_document,
            $sale->old_barcode_product,
            $sale->actual_product_old_price_sale,
        ];
    }
}