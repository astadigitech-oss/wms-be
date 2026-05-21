<?php

namespace App\Exports;

use Illuminate\Http\Request;
use App\Models\SkuProduct; // Sesuaikan dengan path model Anda
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SkuProductsExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    use Exportable;

    protected $querySearch;

    public function __construct(Request $request)
    {
        $this->querySearch = $request->input('q');
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
            'Created At',
            'Updated At'
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
            $this->cleanString($row->barcode_product),
            $this->cleanString($row->name_product),
            $this->cleanString($row->price_product),
            $this->cleanString($row->quantity_product),
            $this->cleanString($row->created_at),
            $this->cleanString($row->updated_at),
        ];
    }

    public function title(): string
    {
        return 'SKU Products';
    }
}