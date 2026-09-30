<?php

namespace App\Exports;

use App\Models\StagingProduct;
use App\Models\New_product;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ExportExtraProduct implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            new ExtraStagingProductSheet(),
            new ExtraNewProductSheet(),
        ];
    }
}

class ExtraStagingProductSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    public function query()
    {
        return StagingProduct::query()
            ->where('is_extra', true)
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Description',
            'Barcode',
            'Barcode Warehouse',
            'Harga Asal',
            'Harga Gudang',
            'Status',
        ];
    }

    public function map($row): array
    {
        return [
            $row->code_document ?? '',
            $row->new_name_product ?? '',
            $row->old_barcode_product ?? '',
            $row->new_barcode_prodcut ?? '',
            $row->old_price_product ?? '',
            $row->new_price_product ?? '',
            $this->formatStatus($row->new_status_product),
        ];
    }

    private function formatStatus($status)
    {
        if ($status === 'display') {
            return 'staging';
        }

        return $status ?? '';
    }

    public function title(): string
    {
        return 'Extra Staging Product';
    }
}

class ExtraNewProductSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    public function query()
    {
        return New_product::query()
            ->where('is_extra', true)
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Description',
            'Barcode',
            'Barcode Warehouse',
            'Harga Asal',
            'Harga Gudang',
            'Status',
        ];
    }

    public function map($row): array
    {
        return [
            $row->code_document ?? '',
            $row->new_name_product ?? '',
            $row->old_barcode_product ?? '',
            $row->new_barcode_prodcut ?? '',
            $row->old_price_product ?? '',
            $row->new_price_product ?? '',
            $this->formatStatus($row->new_status_product),
        ];
    }

    private function formatStatus($status)
    {
        return $status ?? '';
    }

    public function title(): string
    {
        return 'Extra New Product';
    }
}
