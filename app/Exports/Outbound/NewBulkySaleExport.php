<?php

namespace App\Exports\Outbound;

use App\Models\BulkySale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class NewBulkySaleExport implements FromQuery, WithHeadings, WithMapping
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
        return BulkySale::query()
            ->with(['bulkyDocument'])
            ->whereHas('bulkyDocument', function ($q) {
                $q->where('is_sale', 'sale');
            })
            ->when($this->startDate, function ($q) {
                $q->whereDate('updated_at', '>=', $this->startDate);
            })
            ->when($this->endDate, function ($q) {
                $q->whereDate('updated_at', '<=', $this->endDate);
            });
    }

    public function headings(): array
    {
        return [
            'Cargo',
            'Barcode Asal',
            'Barcode Gudang',
            'Nama Produk',
            'Item',
            'Harga Asal',
            'Harga Jual/Gudang',
        ];
    }

    public function map($row): array
    {
        return [
            $row->bulkyDocument->name_document ?? '-',
            $row->old_barcode_product,
            $row->barcode_bulky_sale,
            $row->name_product_bulky_sale,
            $row->qty,
            $row->old_price_bulky_sale,
            $row->after_price_bulky_sale,
        ];
    }
}
