<?php

namespace App\Exports;

use App\Models\ProductEditHistory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductEditHistoryExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $code_document;
    private $rowNumber = 0;

    public function __construct($code_document)
    {
        $this->code_document = $code_document;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return ProductEditHistory::with(['requestUser', 'approverUser'])
            ->where('code_document', $this->code_document)
            ->whereIn('id', function ($subquery) {
                $subquery->selectRaw('MAX(id)')
                    ->from('product_edit_histories')
                    ->where('code_document', $this->code_document)
                    ->groupBy('barcode_product');
            })
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return [
            'No',
            'Code Document',
            'Barcode Produk',
            'Status Approval',
            'Waktu Request',
            'Waktu Approver',
            'User Request',
            'User Approver',
            
            // Kolom Data Lama
            'Data Lama: Nama Produk',
            'Data Lama: Qty',
            'Data Lama: Old Price',
            'Data Lama: New Price',
            'Data Lama: Kategori',
            
            // Kolom Data Baru
            'Data Baru: Nama Produk',
            'Data Baru: Qty',
            'Data Baru: Old Price',
            'Data Baru: New Price',
            'Data Baru: Kategori',
        ];
    }

    public function map($history): array
    {
        $this->rowNumber++;

        // Ekstrak properti JSON agar aman jika null
        $oldValue = $history->old_value ?? [];
        $newValue = $history->new_value ?? [];

        // Format data kualitas (quality) menjadi string untuk Excel
        $qualityString = '-';
        if (isset($newValue['quality']) && is_array($newValue['quality'])) {
            $qualities = [];
            foreach ($newValue['quality'] as $key => $val) {
                if ($val != null) {
                    $qualities[] = ucfirst($key) . ': ' . $val;
                }
            }
            $qualityString = implode(', ', $qualities);
        }

        return [
            $this->rowNumber,
            $history->code_document,
            $history->barcode_product,
            ucfirst($history->status),
            $history->created_at->format('Y-m-d H:i:s'),
            $history->status !== 'pending' ? $history->updated_at->format('Y-m-d H:i:s') : '-',
            $history->requestUser->name ?? 'Unknown',
            $history->approverUser->name ?? '-',
            
            // Mapping Data Lama
            $oldValue['name_product'] ?? '-',
            $oldValue['qty'] ?? 0,
            $oldValue['old_price'] ?? 0,
            $oldValue['new_price'] ?? 0,
            $oldValue['category'] ?? '-',

            // Mapping Data Baru
            $newValue['name_product'] ?? '-',
            $newValue['qty'] ?? 0,
            $newValue['old_price'] ?? 0,
            $newValue['new_price'] ?? 0,
            $newValue['category'] ?? '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEAEAEA']
                ]
            ],
        ];
    }
}