<?php

namespace App\Exports;

use Illuminate\Http\Request;
use App\Models\SkuDocument;
use App\Models\SkuProduct; 
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SkuProductsExport implements WithMultipleSheets
{
    use Exportable;

    protected $querySearch;

    public function __construct(Request $request)
    {
        $this->querySearch = $this->cleanString($request->input('q'));
    }

    private function cleanString($string)
    {
        if (empty($string)) return '';
        
        $string = (string) $string;
        $cleaned = '';
        $length = strlen($string);
        
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($string[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $cleaned .= $string[$i];
            }
        }
        return trim($cleaned);
    }

    public function sheets(): array
    {
        return [
            new SkuDocumentSheet($this->querySearch), 
            new SkuProductSheet($this->querySearch)   
        ];
    }
}


class SkuDocumentSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected $querySearch;

    public function __construct($querySearch)
    {
        $this->querySearch = $querySearch;
    }

    public function query()
    {
        $query = SkuDocument::query();

        if ($this->querySearch) {
            $query->where(function ($subQuery) {
                $subQuery->where('code_document', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('custom_barcode', 'LIKE', '%' . $this->querySearch . '%');
            });
        }

        return $query->orderBy('id', 'desc');
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Base Document',
            'Total Product',
            'Date Document',
            'Custom Barcode'
        ];
    }

    private function cleanString($string)
    {
        if (empty($string)) return '';
        $string = (string) $string;
        $cleaned = '';
        $length = strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($string[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $cleaned .= $string[$i];
            }
        }
        return trim($cleaned);
    }

    public function map($row): array
    {
        return [
            $this->cleanString($row->code_document),
            $this->cleanString($row->base_document),
            $this->cleanString($row->total_column_in_document),
            $this->cleanString($row->date_document),
            $this->cleanString($row->custom_barcode),
        ];
    }

    public function title(): string
    {
        return 'SKU Documents';
    }
}

class SkuProductSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected $querySearch;

    public function __construct($querySearch)
    {
        $this->querySearch = $querySearch;
    }

    public function query()
    {
        $query = SkuProduct::query()->orderBy('created_at', 'desc');

        if ($this->querySearch) {
            $query->where(function ($subQuery) {
                $subQuery->where('code_document', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('barcode_product', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('name_product', 'LIKE', '%' . $this->querySearch . '%');
            });
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Barcode Product',
            'Name Product',
            'Price Product',
            'Quantity',
        ];
    }

    private function cleanString($string)
    {
        if (empty($string)) return '';
        $string = (string) $string;
        $cleaned = '';
        $length = strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($string[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $cleaned .= $string[$i];
            }
        }
        return trim($cleaned);
    }

    public function map($row): array
    {
        return [
            $this->cleanString($row->code_document),
            $this->cleanString($row->barcode_product),
            $this->cleanString($row->name_product),
            $this->cleanString($row->price_product),
            $this->cleanString($row->quantity_product),
        ];
    }

    public function title(): string
    {
        return 'SKU Products';
    }
}