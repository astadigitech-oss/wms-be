<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\DamagedDocument;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocumentController extends Controller
{
    //
    public function destroy(Document $document, Request $request)
    {
        $user = auth()->user()->email;
        try {
            $product_old = Product_old::where('code_document', $document->code_document)->delete();
            $approve = ProductApprove::where('code_document', $document->code_document)->delete();

            // Hapus produk bulk dari excelDamaged (new_products & staging_products) beserta
            // relasi damaged document-nya agar data di GET /damaged ikut terhapus.
            $newProductIds = New_product::where('code_document', $document->code_document)->pluck('id');
            $stagingProductIds = StagingProduct::where('code_document', $document->code_document)->pluck('id');

            $damagedDocuments = DamagedDocument::whereHas('newProducts', function ($q) use ($newProductIds) {
                $q->whereIn('new_products.id', $newProductIds);
            })
                ->orWhereHas('stagingProducts', function ($q) use ($stagingProductIds) {
                    $q->whereIn('staging_products.id', $stagingProductIds);
                })
                ->get();

            foreach ($damagedDocuments as $damagedDocument) {
                $damagedDocument->newProducts()->detach($newProductIds->toArray());
                $damagedDocument->stagingProducts()->detach($stagingProductIds->toArray());
                $damagedDocument->delete();
            }

            New_product::where('code_document', $document->code_document)->delete();
            StagingProduct::where('code_document', $document->code_document)->delete();

            $document->delete();

            logUserAction($request, $request->user(), "inbound/check_product/list_data", "code document " . $document->code_document . "name" . $document->base_document . " deleted by " . $user);

            return new ResponseResource(true, "data berhasil dihapus", $document);
        } catch (\Exception $e) {
            Log::error('[DocumentController@destroy] ' . $e->getMessage(), [
                'code_document' => $document->code_document,
                'trace' => $e->getTraceAsString(),
            ]);
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data: " . $e->getMessage(), null);
        }
    }
}
