<?php

namespace App\Exports;

use App\Models\New_product;
use App\Models\StagingProduct;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;

class ProductCategoryAndColorNull implements FromQuery, WithHeadings
{
    use Exportable;

    public function query()
    {
        return New_product::whereNull('new_tag_product')
            ->whereNull('new_category_product');
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Old Barcode Product',
            'New Barcode Product',
            'New Name Product',
            'New Quantity Product',
            'New Price Product',
            'Old Price Product',
            'New Date In Product',
            'New Status Product',
            'New Quality',
            'New Category Product',
            'New Tag Product',
            'New Discount',
            'Display Price',
            'Days Since Created'
        ];
    }
}
