<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\CogsChannel;
use App\Models\Document;
use App\Models\SkuDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class AttachCogsController extends Controller
{

    public function attachCogsKeDokumenReguler(Request $request, $document_id)
    {
        $validator = Validator::make($request->all(), [
            'channel_id' => 'required|string|exists:cogs_channel,id'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation Error", $validator->errors());
        }

        DB::beginTransaction();

        try {
            $dokumen = Document::where('id', $document_id)->first();

            if (!$dokumen) {
                DB::rollBack();
                return new ResponseResource(false, "Document not found", null);
            }

            $cogsChannel = CogsChannel::where('id', $request->channel_id)->first();

            if (!$cogsChannel) {
                DB::rollBack();
                return new ResponseResource(false, "Channel not found", null);
            }

            $dokumen->update([
                'cogs_type'   => $cogsChannel->type,
                'cogs_amount' => $cogsChannel->amount,
            ]);

            DB::commit();

            return new ResponseResource(true, "Success", null);
        } catch (Exception $e) {
            DB::rollBack();

            return new ResponseResource(
                false,
                "Failed to attach COGS",
                [
                    'message' => $e->getMessage()
                ]
            );
        }
    }

    public function attachCogsKeDokumenSku(Request $request, $document_id)
    {
        $validator = Validator::make($request->all(), [
            'channel_id' => 'required|string|exists:cogs_channel,id'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation Error", $validator->errors());
        }

        DB::beginTransaction();

        try {
            $dokumen = SkuDocument::where('id', $document_id)->first();

            if (!$dokumen) {
                DB::rollBack();
                return new ResponseResource(false, "Document not found", null);
            }

            $cogsChannel = CogsChannel::where('id', $request->channel_id)->first();

            if (!$cogsChannel) {
                DB::rollBack();
                return new ResponseResource(false, "Channel not found", null);
            }

            $dokumen->update([
                'cogs_type'   => $cogsChannel->type,
                'cogs_amount' => $cogsChannel->amount,
            ]);

            DB::commit();

            return new ResponseResource(true, "Success", null);
        } catch (Exception $e) {
            DB::rollBack();

            return new ResponseResource(
                false,
                "Failed to attach COGS",
                $e->getMessage()
            );
        }
    }

    public function getCategories()
    {
        try {
            $newProductCategories = DB::table('new_products')
                ->select(
                    'new_category_product',
                    DB::raw('COUNT(*) as total')
                )
                ->whereNotNull('new_category_product')
                ->where('new_category_product', '!=', '')
                ->groupBy('new_category_product')
                ->orderBy('new_category_product')
                ->get()
                ->map(function ($item) {
                    return "{$item->new_category_product} ({$item->total})";
                });

            $stagingProductCategories = DB::table('staging_products')
                ->select(
                    'new_category_product',
                    DB::raw('COUNT(*) as total')
                )
                ->whereNotNull('new_category_product')
                ->where('new_category_product', '!=', '')
                ->groupBy('new_category_product')
                ->orderBy('new_category_product')
                ->get()
                ->map(function ($item) {
                    return "{$item->new_category_product} ({$item->total})";
                });
            return new ResponseResource(true, 'Success', [
                'new_products' => $newProductCategories,
                'staging_products' => $stagingProductCategories,
            ]);
        } catch (\Exception $e) {
            return new ResponseResource(false, 'Failed to get categories', [
                'message' => $e->getMessage()
            ]);
        }
    }
}
