<?php

namespace App\Exports\Outbound;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class NewSaleExport implements FromQuery, WithHeadings, WithMapping
{
    protected $startDate;
    protected $endDate;

    public function __construct($request)
    {
        $this->startDate = $request->start_date;
        $this->endDate = $request->end_date;
    }

    public function query()
    {
        $query = Sale::query();

        if ($this->startDate) {
            $query->whereDate('created_at', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $query->whereDate('created_at', '<=', $this->endDate);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Document Penjualan',
            'Barcode Gudang',
            'Barcode Asal',
            'Nama Produk',
            'Total Item',
            'Kategori',
            'Harga Asal',
            'Harga Jual',
            'Harga Jual Setelah Diskon Rank'
        ];
    }

    public function map($sale): array
    {
        return [
            $sale->code_document_sale,
            $sale->product_barcode_sale,
            $sale->old_barcode_product,
            $sale->product_name_sale,
            $sale->product_qty_sale,
            $sale->product_category_sale,
            $sale->product_old_price_sale,
            $sale->display_price,
            $sale->product_price_sale,
        ];
    }
}
