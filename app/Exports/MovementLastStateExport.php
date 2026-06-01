<?php

namespace App\Exports;

use App\Services\MovementService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MovementLastStateExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        protected string $lokasi,
        protected ?string $tanggal = null
    ) {}

    public function query()
    {
        return MovementService::getLastStateQuery($this->lokasi, $this->tanggal);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Product ID',
            'Nama Produk',
            'Is SKU',
            'Type',
            'Type Out',
            'From',
            'To',
            'Qty',
            'Price After',
            'Price Before',
            'DateTime',
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->product_id,
            $row->nama_produk ?? '-',
            $row->is_sku ? 'Ya' : 'Tidak',
            $row->type,
            $row->type_out ?? '-',
            $row->from,
            $row->to,
            $row->qty ?? '-',
            $row->price_after ?? '-',
            $row->price_before ?? '-',
            $row->dateTime,
        ];
    }
}
