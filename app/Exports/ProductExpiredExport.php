<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductExpiredExport implements FromCollection, WithHeadings
{
    protected $products;

    // Konstruktor untuk menerima data produk
    public function __construct($products)
    {
        $this->products = $products;
    }

    // Mengembalikan collection data yang akan diekspor
    public function collection()
    {
        return collect($this->products);
    }

    public function headings(): array
    {

        return ["Barcode", "Nama Produk", "Harga", "Qty", "Lama Expired"];
    }
}
