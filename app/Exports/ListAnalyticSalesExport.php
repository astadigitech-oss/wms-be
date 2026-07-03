<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ListAnalyticSalesExport implements FromCollection, WithHeadings
{
    protected $listAnalyticSales;

    // Konstruktor untuk menerima data produk
    public function __construct($listAnalyticSales)
    {
        $this->listAnalyticSales = $listAnalyticSales;
    }

    // Mengembalikan collection data yang akan diekspor
    public function collection()
    {
        return collect($this->listAnalyticSales);
    }

    public function headings(): array
    {

        return ["Category Name", "Qty", "Display Price", "Sale Price"];
    }
}
