<?php

namespace App\Exports\Inventory;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CekSkuExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $staging = DB::table('staging_products as sp')
            ->join('sku_products as sku', 'sku.barcode_product', '=', 'sp.old_barcode_product')
            ->where('sp.code_document', 'like', 'SKU%')
            ->selectRaw("
                'STAGING' as source,
                sp.code_document,
                sp.old_barcode_product,
                sp.old_price_product,
                sp.new_quantity_product,
                sku.price_product,
                (sp.old_price_product / sku.price_product) as calculated_qty,
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(sp.old_price_product, sku.price_product) = 0
                    AND (sp.old_price_product / sku.price_product) = sp.new_quantity_product
                    THEN 'SESUAI'
                    ELSE 'TIDAK SESUAI'
                END as status
            ");

        $new = DB::table('new_products as np')
            ->join('sku_products as sku', 'sku.barcode_product', '=', 'np.old_barcode_product')
            ->where('np.code_document', 'like', 'SKU%')
            ->selectRaw("
                'NEW' as source,
                np.code_document,
                np.old_barcode_product,
                np.old_price_product,
                np.new_quantity_product,
                sku.price_product,
                (np.old_price_product / sku.price_product) as calculated_qty,
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(np.old_price_product, sku.price_product) = 0
                    AND (np.old_price_product / sku.price_product) = np.new_quantity_product
                    THEN 'SESUAI'
                    ELSE 'TIDAK SESUAI'
                END as status
            ");

        return $staging
            ->unionAll($new)
            ->get();
    }

    public function headings(): array
    {
        return [
            'Source',
            'Code Document',
            'Barcode',
            'Old Price',
            'Qty Baru',
            'Harga SKU',
            'Qty Hasil Bagi',
            'Status',
        ];
    }
}
