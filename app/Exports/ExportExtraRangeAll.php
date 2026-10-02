<?php

namespace App\Exports;

use App\Models\StagingProduct;
use App\Models\New_product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ExportExtraRangeAll implements FromCollection, WithHeadings, WithMapping
{
    protected $start;
    protected $end;
    public function __construct($start = null, $end = null)
    {
        $this->start = $start ?? '2026-01-01';
        $this->end = $end ?? now()->toDateString();
    }
    public function collection()
    {
        $stagingProducts = StagingProduct::query()
            ->where('is_extra', true)
            ->whereBetween('new_date_in_product', [
                $this->start,
                $this->end,
            ])
            ->where(function ($query) {
                $query->whereNull('code_document')
                    ->orWhere('code_document', '');
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($row) {
                $row->source_table = 'Staging Product';
                return $row;
            });

        $newProducts = New_product::query()
            ->where('is_extra', true)
            ->whereBetween('new_date_in_product', [
                $this->start,
                $this->end,
            ])
            ->where(function ($query) {
                $query->whereNull('code_document')
                    ->orWhere('code_document', '');
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($row) {
                $row->source_table = 'New Product';
                return $row;
            });

        return $stagingProducts
            ->concat($newProducts)
            ->sortByDesc('created_at')
            ->values();
    }
    public function headings(): array
    {
        return ['Source Table', 'Code Document', 'Description', 'Barcode', 'Barcode Warehouse', 'Harga Asal', 'Harga Gudang', 'Status', 'Tanggal Masuk',];
    }
    public function map($row): array
    {
        $status = $row->new_status_product ?? '';
        if ($row->source_table === 'Staging Product' && $status === 'display') {
            $status = 'staging';
        }
        return [$row->source_table, $row->code_document ?? '', $row->new_name_product ?? '', $row->old_barcode_product ?? '', $row->new_barcode_product ?? '', $row->old_price_product ?? '', $row->new_price_product ?? '', $status, $row->new_date_in_product ?? '',];
    }
}
