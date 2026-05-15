<?php

namespace App\Exports;

use App\Models\ColorRackProduct;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ColorRackProductExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $rackId;
    protected $searchQuery;

    public function __construct($rackId, $searchQuery = null)
    {
        $this->rackId = $rackId;
        $this->searchQuery = $searchQuery;
    }

    public function query()
    {
        $productQuery = ColorRackProduct::with(['newProduct', 'bundle'])->where('color_rack_id', $this->rackId);

        if ($this->searchQuery && $this->searchQuery != '') {
            $q = $this->searchQuery;
            $productQuery->where(function ($mainQ) use ($q) {
                $mainQ->whereHas('newProduct', function ($subQ) use ($q) {
                    $subQ->where('new_name_product', 'LIKE', "%{$q}%")
                        ->orWhere('new_barcode_product', 'LIKE', "%{$q}%")
                        ->orWhere('old_barcode_product', 'LIKE', "%{$q}%");
                })
                ->orWhereHas('bundle', function ($subQ) use ($q) {
                    $subQ->where('name_bundle', 'LIKE', "%{$q}%")
                        ->orWhere('barcode_bundle', 'LIKE', "%{$q}%");
                });
            });
        }

        return $productQuery->latest();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Item ID',
            'Tipe',
            'Nama Produk',
            'New Barcode',
            'Old Barcode',
            'Tag/Color',
            'Status',
            'New Price',
            'Old Price',
            'Added At'
        ];
    }

    public function map($item): array
    {
        if ($item->bundle_id && $item->bundle) {
            return [
                $item->id,
                $item->bundle->id,
                'Bundle',
                "[BUNDLE] " . $item->bundle->name_bundle,
                $item->bundle->barcode_bundle,
                $item->bundle->old_barcode_bundle,
                $item->bundle->name_color,
                $item->bundle->product_status,
                $item->bundle->total_price_custom_bundle,
                $item->bundle->total_price_bundle,
                $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
            ];
        } else if ($item->newProduct) {
            return [
                $item->id,
                $item->newProduct->id,
                'Product',
                $item->newProduct->new_name_product,
                $item->newProduct->new_barcode_product,
                $item->newProduct->old_barcode_product,
                $item->newProduct->new_tag_product,
                $item->newProduct->new_status_product,
                $item->newProduct->new_price_product ?? $item->newProduct->new_price_eq,
                $item->newProduct->old_price_product ?? $item->newProduct->old_price_eq,
                $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
            ];
        }

        return [];
    }
}