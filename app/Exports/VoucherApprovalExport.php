<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class VoucherApprovalExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        return VoucherApproval::with([
            'voucher:id,name,max_usage',
            'requester:id,name',
            'approver:id,name',
            'buyer',
        ])
            ->when($this->request->filled('q'), function ($query) {
                $query->whereHas('requester', function ($q) {
                    $q->where(
                        'name',
                        'like',
                        '%' . $this->request->q . '%'
                    );
                });
            })
            ->when($this->request->filled('status'), function ($query) {
                if ($this->request->status !== 'all') {
                    $query->where('status', $this->request->status);
                }
            })
            ->latest('date_request')
            ->get();
    }

    public function map($approval): array
    {
        $pivot = $approval->buyer
            ?->vouchers()
            ->where('voucher_id', $approval->voucher_id)
            ->first()?->pivot;

        return [
            $approval->voucher?->name ?? 'Voucher Manual',
            $approval->requester?->name,
            $approval->approver?->name,
            $approval->voucher?->max_usage ?? $approval->nominal,
            $approval->buyer?->name_buyer,
            $approval->voucher_id
                ? ($pivot?->used ?? 0)
                : ($approval->usage ?? 0),
            $approval->status,
            $approval->date_request,
            $approval->date_approved,
        ];
    }

    public function headings(): array
    {
        return [
            'Voucher',
            'Requested By',
            'Approved By',
            'Nominal',
            'Buyer',
            'Usage',
            'Status',
            'Date Request',
            'Date Approved',
        ];
    }
}
