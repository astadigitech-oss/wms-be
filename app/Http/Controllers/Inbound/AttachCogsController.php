<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\CogsChannel;
use App\Models\CogsReference;
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
            'channel_id' => 'required|string|exists:cogs_channels,id'
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


            $cogsReference = CogsReference::where('document_id', $dokumen->id)
                ->where('type', 'reguler')
                ->first();

            if ($cogsReference) {
                $cogsReference->update([
                    'cogs_channel_id' => $request->channel_id,
                    'user_id'    => $request->user()->id,
                ]);
            } else {
                $cogsReference = CogsReference::create([
                    'cogs_channel_id'  => $request->channel_id,
                    'type'        => 'reguler',
                    'document_id' => $dokumen->id,
                    'user_id'     => $request->user()->id,
                ]);
            }

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
            'channel_id' => 'required|string|exists:cogs_channels,id'
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

            $cogsReference = CogsReference::where('document_id', $dokumen->id)
                ->where('type', 'sku')
                ->first();

            if ($cogsReference) {
                $cogsReference->update([
                    'cogs_channel_id' => $request->channel_id,
                    'user_id'    => $request->user()->id,
                ]);
            } else {
                $cogsReference = CogsReference::create([
                    'cogs_channel_id'  => $request->channel_id,
                    'type'        => 'sku',
                    'document_id' => $dokumen->id,
                    'user_id'     => $request->user()->id,
                ]);
            }
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
                ->selectRaw("
                new_category_product,
                COUNT(*) as total,
                ROUND(
                    AVG(
                        ((old_price_product - new_price_product)
                        / NULLIF(old_price_product, 0)) * 100
                    ),
                    0
                ) as avg_discount
            ")
                ->whereNotNull('new_category_product')
                ->where('new_category_product', '!=', '')
                ->groupBy('new_category_product')
                ->orderBy('new_category_product')
                ->get()
                ->map(function ($item) {
                    return "{$item->new_category_product} ({$item->total}) ({$item->avg_discount}%)";
                });

            $stagingProductCategories = DB::table('staging_products')
                ->selectRaw("
                new_category_product,
                COUNT(*) as total,
                ROUND(
                    AVG(
                        ((old_price_product - new_price_product)
                        / NULLIF(old_price_product, 0)) * 100
                    ),
                    0
                ) as avg_discount
            ")
                ->whereNotNull('new_category_product')
                ->where('new_category_product', '!=', '')
                ->groupBy('new_category_product')
                ->orderBy('new_category_product')
                ->get()
                ->map(function ($item) {
                    return "{$item->new_category_product} ({$item->total}) ({$item->avg_discount}%)";
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

    public function getCogsChannels()
    {
        try {
            $cogsChannels = CogsChannel::all();

            return new ResponseResource(true, 'Success', $cogsChannels);
        } catch (\Exception $e) {
            return new ResponseResource(false, 'Failed to get COGS channels', [
                'message' => $e->getMessage()
            ]);
        }
    }
}
