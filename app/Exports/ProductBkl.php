<?php

namespace App\Exports;

use App\Models\Bkl;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductBkl implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $query;

    public function __construct(Request $request)
    {
        $this->query = $request->input('q'); 
    } 

    public function query()
    {
        $productBkl = Bkl::latest();
        return $productBkl;
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
            'Days Since Created',
            'Days Since Updated',
        ];
    }

    public function map($product): array
    {
        return [
            $product->code_document,
            $product->old_barcode_product,
            $product->new_barcode_product,
            $product->new_name_product,
            $product->new_quantity_product,
            $product->new_price_product,
            $product->old_price_product,
            $product->new_date_in_product,
            $product->new_status_product,
            $product->new_quality,
            $product->new_category_product,
            $product->new_tag_product,
            $product->new_discount,
            $product->display_price,
            $product->days_since_created,
            $product->days_since_updated,
        ];
    }

    /**
     * Chunk size per read operation
     */
    public function chunkSize(): int
    {
        return 500;
    }
}
