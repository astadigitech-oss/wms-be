<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;

class ProductsExportCategory implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;
    
    protected $dataCollection;

    public function __construct($dataCollection)
    {
        $this->dataCollection = $dataCollection;
    }

    public function collection()
    {
        return $this->dataCollection;
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
            'Weight',
            'Barcode Rak'
        ];
    }

    private function cleanString($string)
    {
        if (is_null($string) || $string === '') {
            return '';
        }

        $string = (string) $string;
        $string = htmlspecialchars_decode(htmlspecialchars($string, ENT_IGNORE | ENT_SUBSTITUTE, 'UTF-8'));
        $string = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $string);

        return trim($string);
    }

    public function map($row): array
    {
        return [
            $this->cleanString($row->code_document),
            $this->cleanString($row->old_barcode_product),
            $this->cleanString($row->new_barcode_product),
            $this->cleanString($row->new_name_product),
            $this->cleanString($row->new_quantity_product),
            $this->cleanString($row->new_price_product),
            $this->cleanString($row->old_price_product),
            $this->cleanString($row->new_date_in_product),
            $this->cleanString($row->new_status_product),
            $this->cleanString($row->new_quality),
            $this->cleanString($row->new_category_product),
            $this->cleanString($row->weight),
            $this->cleanString($row->barcode_rack),
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }
}