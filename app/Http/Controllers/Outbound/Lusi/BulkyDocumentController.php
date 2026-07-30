<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BulkyDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BulkyDocumentController extends Controller
{
    //
    public function confirmSale(Request $request, $id)
    {
        $doc = BulkyDocument::with('bulkySales')->findOrFail($id);
        $isOnline = $doc->type === BulkyDocument::TYPE_ONLINE;

        $validator = Validator::make($request->all(), [
            'buyer_id'       => $isOnline ? 'nullable|exists:buyers,id' : 'required|exists:buyers,id',
            'discount_bulky' => $isOnline ? 'nullable|numeric|min:0|max:100' : 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        if ($doc->is_sale === BulkyDocument::SALE) {
            return (new ResponseResource(false, "Dokumen ini sudah berstatus terjual!", null))->response()->setStatusCode(400);
        }

        if ($doc->status_bulky === 'proses') {
            return (new ResponseResource(false, "Dokumen ini masih proses!", null))->response()->setStatusCode(400);
        }

        if ($doc->type === BulkyDocument::TYPE_ONLINE && $doc->is_sale !== BulkyDocument::SALE_READY) {
            return (new ResponseResource(false, "Dokumen Cargo Online belum siap dijual (Ready)! Silakan lengkapi data dimensi/armada terlebih dahulu.", null))->response()->setStatusCode(400);
        }

        $buyer = $request->filled('buyer_id') ? \App\Models\Buyer::find($request->buyer_id) : null;

        $discountBulky = $request->discount_bulky ?? 0;

        $user = auth()->user();

        DB::beginTransaction();
        try {
            $totalAfterPrice = 0;
            foreach ($doc->bulkySales as $item) {
                $newPrice = $item->old_price_bulky_sale - ($item->old_price_bulky_sale * $discountBulky / 100);
                $item->update(['after_price_bulky_sale' => $newPrice]);
                $totalAfterPrice += $newPrice;
            }

            $doc->update([
                'is_sale'           => BulkyDocument::SALE,
                'buyer_id'          => $buyer ? $buyer->id : null,
                'name_buyer'        => $buyer ? $buyer->name_buyer : null,
                'discount_bulky'    => $discountBulky,
                'after_price_bulky' => $totalAfterPrice,
                'user_id_set_sale'  => $user?->id,
            ]);

            DB::commit();
            return (new ResponseResource(true, "Cargo {$doc->type} berhasil terjual!", $doc))->response();
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Error: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }
}
