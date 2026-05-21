<?php

namespace App\Exports;

use App\Models\BulkySale;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class BulkySalesExport implements FromQuery, WithTitle, WithHeadings, WithMapping
{
    use Exportable;

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
        $statusTitle = $this->filterStatus ? ucfirst($this->filterStatus) : 'All';
        return 'Bulky Sales - ' . $statusTitle;
    }
}