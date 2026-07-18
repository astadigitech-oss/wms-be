<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\SaleDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    //
    public function generalSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $fromDate = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : Carbon::now()->startOfMonth()->startOfDay();
        $toDate = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : Carbon::now()->endOfMonth()->endOfDay();

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentMonth = $currentDate->format('F');
        $currentYear = $currentDate->format('Y');

        $generalSale = SaleDocument::selectRaw('
                SUM(total_price_document_sale) as total_price_sale,
                SUM(total_old_price_document_sale) as total_display_price,
                code_document_sale,
                buyer_name_document_sale,
                DATE(created_at) as tgl
            ')
            ->where('status_document_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('tgl', 'code_document_sale', 'buyer_name_document_sale')
            ->get()
            ->groupBy('tgl')
            ->map(function ($salesOnDate) {
                $total_price_sale = $salesOnDate->sum('total_price_sale');
                $total_display_price = $salesOnDate->sum('total_display_price');
                $date = Carbon::parse($salesOnDate->first()->tgl)->format('d-m-Y');
                return [
                    "date" => $date,
                    "total_price_sale" => $total_price_sale,
                    "total_display_price" => $total_display_price,
                ];
            })->values();

        $listDocumentSale = SaleDocument::selectRaw('
                id,
                total_price_document_sale as total_purchase,
                total_old_price_document_sale as total_display_price,
                code_document_sale,
                buyer_name_document_sale
            ')
            ->where('status_document_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $listTopBuyerQuery = SaleDocument::selectRaw('
                buyer_id_document_sale,
                MAX(buyer_name_document_sale) as buyer_name_document_sale,
                SUM(total_price_document_sale) as total_purchase,
                FLOOR(SUM(CASE
                    WHEN total_price_document_sale >= 5000000
                    THEN total_price_document_sale
                    ELSE 0
                END) / 1000) as total_point,
                COUNT(*) as transaction_count
            ')
            ->where('status_document_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('buyer_id_document_sale')
            ->havingRaw('SUM(CASE WHEN total_price_document_sale >= 5000000 THEN total_price_document_sale ELSE 0 END) > 0')
            ->orderByDesc('total_point')
            ->limit(10)
            ->get();

        $buyerIds = $listTopBuyerQuery->pluck('buyer_id_document_sale')->filter()->values();
        $buyers = Buyer::whereIn('id', $buyerIds)->get()->keyBy('id');

        $listTopBuyer = $listTopBuyerQuery->map(function ($row) use ($buyers) {
            $buyer = $buyers->get($row->buyer_id_document_sale);
            $buyerName = $buyer?->name_buyer ?? $row->buyer_name_document_sale ?? 'Unknown Buyer';

            return [
                'buyer_id' => (int) $row->buyer_id_document_sale,
                'buyer' => [
                    'id' => (int) $row->buyer_id_document_sale,
                    'name_buyer' => $buyerName,
                ],
                'total_purchase' => (float) $row->total_purchase,
                'total_point' => (int) $row->total_point,
                'transaction_count' => (int) $row->transaction_count,
            ];
        })->values();

        $resource = new ResponseResource(
            true,
            "Laporan Data General",
            [
                'month' => [
                    'current_month' => [
                        'month' => $currentMonth,
                        'year' => $currentYear,
                    ],
                    'date_from' => [
                        'date' => $fromInput ? $fromDate->format('d') : null,
                        'month' => $fromInput ? $fromDate->format('M') : null,
                        'year' => $fromInput ? $fromDate->format('Y') : null,
                    ],
                    'date_to' => [
                        'date' => $toInput ? $toDate->format('d') : null,
                        'month' => $toInput ? $toDate->format('M') : null,
                        'year' => $toInput ? $toDate->format('Y') : null,
                    ],
                ],
                'chart' => $generalSale,
                'list_document_sale' => $listDocumentSale,
                'list_top_buyer' => $listTopBuyer,
            ]
        );

        return $resource->response();
    }
}
