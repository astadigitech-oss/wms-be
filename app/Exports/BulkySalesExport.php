<?php

namespace App\Exports;

use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class BulkySalesExport implements WithMultipleSheets
{
    use Exportable;

    protected $filterStatus;
    protected $querySearch;

    public function __construct($filterStatus, $querySearch)
    {
        $this->filterStatus = $filterStatus;
        $this->querySearch = $this->cleanString($querySearch);
    }

    private function cleanString($value)
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        // Buang byte UTF-8 yang rusak
        $value = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($value === false) {
            $value = '';
        }

        // Hapus karakter kontrol yang tidak bisa ditulis Excel
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    public function sheets(): array
    {
        return [
            new BulkyDocumentSheet($this->filterStatus, $this->querySearch),
            new BulkySaleSheet($this->filterStatus, $this->querySearch)
        ];
    }
}

class BulkyDocumentSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected $filterStatus;
    protected $querySearch;

    public function __construct($filterStatus, $querySearch)
    {
        $this->filterStatus = $filterStatus;
        $this->querySearch = $querySearch;
    }

    public function query()
    {
        $query = BulkyDocument::query();

        if ($this->filterStatus === 'proses') {
            $query->whereIn('is_sale', ['ready', 'not sale']);
        } elseif ($this->filterStatus === 'sale') {
            $query->where('is_sale', 'sale');
        }

        if ($this->querySearch) {
            $query->where(function ($subQuery) {
                $subQuery->where('name_document', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('code_document_bulky', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('name_user', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('name_buyer', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('category_bulky', 'LIKE', '%' . $this->querySearch . '%');
            });
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Code Document Bulky',
            'Document Name',
            'Category',
            'Type',
            'Total Product',
            'Total Old Price',
            'Discount (%)',
            'After Price',
            'Status Bulky',
            'Is Sale',
            'Created At'
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
            $this->cleanString($row->code_document_bulky),
            $this->cleanString($row->name_document),
            $this->cleanString($row->category_bulky),
            $this->cleanString($row->type),
            $this->cleanString($row->total_product_bulky),
            $this->cleanString($row->total_old_price_bulky),
            $this->cleanString($row->discount_bulky),
            $this->cleanString($row->after_price_bulky),
            $this->cleanString($row->status_bulky),
            $this->cleanString($row->is_sale),
            $this->cleanString($row->created_at),
        ];
    }

    public function title(): string
    {
        return 'Bulky Documents';
    }
}

class BulkySaleSheet implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    protected $filterStatus;
    protected $querySearch;

    public function __construct($filterStatus, $querySearch)
    {
        $this->filterStatus = $filterStatus;
        $this->querySearch = $querySearch;
    }

    public function query()
    {
        $query = BulkySale::query()
            ->select(
                'bulky_sales.id',
                'bulky_sales.barcode_bulky_sale',
                'bulky_sales.old_barcode_product',
                'bulky_sales.name_product_bulky_sale',
                'bulky_sales.product_category_bulky_sale',
                'bulky_sales.qty',
                'bulky_sales.old_price_bulky_sale',
                'bulky_sales.after_price_bulky_sale',
                'bulky_sales.display_price',
                'bulky_sales.status_product_before',
                'bulky_sales.created_at',
                'bulky_documents.name_document',
                'bulky_documents.type as document_type',
                'bulky_documents.is_sale as document_status',
                'bag_products.name_bag'
            )
            ->leftJoin('bulky_documents', 'bulky_sales.bulky_document_id', '=', 'bulky_documents.id')
            ->leftJoin('bag_products', 'bulky_sales.bag_product_id', '=', 'bag_products.id');

        if ($this->filterStatus === 'proses') {
            $query->whereIn('bulky_documents.is_sale', ['ready', 'not sale']);
        } elseif ($this->filterStatus === 'sale') {
            $query->where('bulky_documents.is_sale', 'sale');
        }

        if ($this->querySearch) {
            $query->where(function ($subQuery) {
                $subQuery->where('bulky_sales.barcode_bulky_sale', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('bulky_sales.old_barcode_product', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('bulky_sales.name_product_bulky_sale', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('bulky_documents.name_document', 'LIKE', '%' . $this->querySearch . '%')
                    ->orWhere('bag_products.name_bag', 'LIKE', '%' . $this->querySearch . '%');
            });
        }

        return $query->orderBy('bulky_sales.created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Document Name',
            'Document Type',
            'Bag Name',
            'Barcode Bulky Sale',
            'Old Barcode Product',
            'Product Name',
            'Product Category',
            'Qty',
            'Old Price',
            'After Price',
            'Display Price',
            'Status Product Before',
            'Document Status',
            'Created At'
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
            $this->cleanString($row->name_document ?? ''),
            $this->cleanString($row->document_type ?? ''),
            $this->cleanString($row->name_bag ?? ''),
            $this->cleanString($row->barcode_bulky_sale),
            $this->cleanString($row->old_barcode_product),
            $this->cleanString($row->name_product_bulky_sale),
            $this->cleanString($row->product_category_bulky_sale),
            $this->cleanString($row->qty),
            $this->cleanString($row->old_price_bulky_sale),
            $this->cleanString($row->after_price_bulky_sale),
            $this->cleanString($row->display_price),
            $this->cleanString($row->status_product_before),
            $this->cleanString($row->document_status),
            $this->cleanString($row->created_at),
        ];
    }

    public function title(): string
    {
        return 'Bulky Sales Product';
    }
}
