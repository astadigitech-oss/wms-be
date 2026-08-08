<?php

namespace App\Exports\Outbound;

use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class NewCargoExport implements WithMultipleSheets
{
    protected $exportDate;

    public function __construct($exportDate = null)
    {
        // Jika tanggal null/kosong, otomatis default ke hari ini (23:59:59)
        $this->exportDate = !empty($exportDate)
            ? Carbon::parse($exportDate)->endOfDay()
            : now()->endOfDay();
    }

    public function sheets(): array
    {
        // Ambil ID dokumen yang created_at <= exportDate
        $documentIds = BulkyDocument::query()
            ->where('is_sale', BulkyDocument::SALE_NOT)
            ->where('type', BulkyDocument::TYPE_OFFLINE)
            ->where('created_at', '<=', $this->exportDate)
            ->pluck('id');

        return [

            // Sheet 1 - Dokumen B2B
            new class($documentIds, $this->exportDate) implements FromCollection, WithTitle, WithHeadings, WithMapping {

                public function __construct(
                    protected $documentIds,
                    protected $exportDate
                ) {}

                public function collection()
                {
                    return BulkyDocument::query()
                        ->whereIn('id', $this->documentIds)
                        ->where('created_at', '<=', $this->exportDate)
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
            new class($documentIds, $this->exportDate) implements FromCollection, WithTitle, WithHeadings, WithMapping {

                public function __construct(
                    protected $documentIds,
                    protected $exportDate
                ) {}

                public function collection()
                {
                    return BulkySale::query()
                        ->with('bulkyDocument')
                        ->whereIn('bulky_document_id', $this->documentIds)
                        ->where('created_at', '<=', $this->exportDate)
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
