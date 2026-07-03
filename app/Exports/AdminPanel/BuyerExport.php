<?php

namespace App\Exports\AdminPanel;

use App\Models\Buyer;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BuyerExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Buyer::query()
            ->leftJoin('sale_documents', 'buyers.id', '=', 'sale_documents.buyer_id_document_sale')
            ->select(
                'buyers.id',
                'buyers.name_buyer as name',
                DB::raw('COUNT(sale_documents.id) as total_transaction'),
                DB::raw('COALESCE(SUM(sale_documents.total_price_document_sale), 0) as total_spending')
            )
            ->groupBy(
                'buyers.id',
                'buyers.name_buyer',
            )
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nama',
            'Jumlah Belanja',
            'Total Nominal Belanja'
        ];
    }
}
