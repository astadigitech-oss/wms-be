<?php

namespace App\Exports\Outbound;

use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class NewCargoExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $documentIds = BulkyDocument::query()
            ->where('is_sale', BulkyDocument::SALE_NOT)
            ->where('type', BulkyDocument::TYPE_OFFLINE)
            ->pluck('id');

        return [

            // Sheet 1 - Dokumen B2B
            new class($documentIds) implements FromCollection, WithTitle, WithHeadings, WithMapping {

                public function __construct(protected $documentIds) {}

                public function collection()
                {
                    return BulkyDocument::query()
                        ->whereIn('id', $this->documentIds)
                        ->get();
                }

                public function headings(): array
                {
                    return [
                        'Kode',
                        'Nama',
                        'Total Item',
                        'Total Price Source',
                    ];
                }

                public function map($row): array
                {
                    return [
                        $row->code_document_bulky,
                        $row->name_document,
                        $row->total_product_bulky,
                        $row->total_old_price_bulky,
                    ];
                }

                public function title(): string
                {
                    return 'Dokumen B2B';
                }
            },

            // Sheet 2 - Produk B2B
            new class($documentIds) implements FromCollection, WithTitle, WithHeadings, WithMapping {

                public function __construct(protected $documentIds) {}

                public function collection()
                {
                    return BulkySale::query()
                        ->with('bulkyDocument')
                        ->whereIn('bulky_document_id', $this->documentIds)
                        ->get();
                }

                public function headings(): array
                {
                    return [
                        'Kode Cargo',
                        'Barcode Asal',
                        'Barcode Gudang',
                        'Item',
                        'Price Source',
                        'Kategori',
                    ];
                }

                public function map($row): array
                {
                    return [
                        $row->bulkyDocument?->code_document_bulky,
                        $row->old_barcode_product,
                        $row->barcode_bulky_sale,
                        $row->qty,
                        $row->old_price_bulky_sale,
                        $row->product_category_bulky_sale,
                    ];
                }

                public function title(): string
                {
                    return 'Produk B2B';
                }
            },
        ];
    }
}
