<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\ApproveQueue;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\ProductEditHistory;
use App\Models\SaleDocument;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApproveQueueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ApproveQueue $approveQueue)
    {
        //
    }

    public function approve(Request $request)
    {
        DB::beginTransaction();
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        try {
            $user = auth()->user()->email;
            $approveQueue = ApproveQueue::find($request->id);
            $barcode = '';

            if (!$approveQueue) {
                return (new ResponseResource(false, "Approve queue not found", null))->response()->setStatusCode(404);
            }
            if ($approveQueue->status == '0') {
                return (new ResponseResource(false, "Sudah di approve", null))->response()->setStatusCode(404);
            }

            if ($approveQueue->type == 'inventory') {
                $newProduct = New_product::find($approveQueue->product_id);
                $newProduct->update([
                    'old_price_product' => $approveQueue->old_price_product,
                    'new_name_product' => $approveQueue->new_name_product,
                    'new_quantity_product' => $approveQueue->new_quantity_product,
                    'new_price_product' => $approveQueue->new_price_product,
                    'new_discount' => $approveQueue->new_discount,
                    'new_tag_product' => $approveQueue->new_tag_product,
                    'new_category_product' => $approveQueue->new_category_product,
                    'display_price' => $approveQueue->new_price_product,
                ]);
                $barcode = $newProduct->new_barcode_product;
                $newProduct->save();
            }

            if ($approveQueue->type == 'staging') {
                $stagingProduct = StagingProduct::find($approveQueue->product_id);
                $stagingProduct->update([
                    'old_price_product' => $approveQueue->old_price_product,
                    'new_name_product' => $approveQueue->new_name_product,
                    'new_quantity_product' => $approveQueue->new_quantity_product,
                    'new_price_product' => $approveQueue->new_price_product,
                    'new_discount' => $approveQueue->new_discount,
                    'new_tag_product' => $approveQueue->new_tag_product,
                    'new_category_product' => $approveQueue->new_category_product,
                    'display_price' => $approveQueue->new_price_product,
                ]);
                $barcode = $stagingProduct->new_barcode_product;
                $stagingProduct->save();
            }

            if ($approveQueue->type == 'inventory' && $approveQueue->notification_id) {
                // Untuk type inventory, notification sudah terhubung langsung lewat notification_id
                $notification = Notification::find($approveQueue->notification_id);
            } else {
                $notification = Notification::where('user_id', $approveQueue->user_id)
                    ->where('status', $approveQueue->type)->where('external_id', $approveQueue->product_id)
                    ->first();
            }

            $approveQueue->update(['status' => '0']);

            if ($notification) {
                $notification->update(['approved' => '2']);

                $history = ProductEditHistory::where('notification_id', $notification->id)->first();
                if ($history) {
                    $history->update([
                        'status' => 'approved',
                        'approver_id' => auth()->id()
                    ]);
                }
            }

            logUserAction($request, $request->user(), "Notification/approve", "product " . $approveQueue->type . " dengan barcode " . $barcode . " di approve->" . $user);

            DB::commit();
            return new ResponseResource(true, "Approved successfully", $barcode);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Failed to approve", null))->response()->setStatusCode(500);
        }
    }

    public function reject(Request $request)
    {
        $approveQueue = ApproveQueue::find($request->id);
        if (!$approveQueue) {
            return (new ResponseResource(false, "Approve queue not found", null))->response()->setStatusCode(404);
        }

        if ($approveQueue->type == 'inventory' && $approveQueue->notification_id) {
            // Untuk type inventory, notification sudah terhubung langsung lewat notification_id
            $notification = Notification::find($approveQueue->notification_id);
        } else {
            $notification = Notification::where('user_id', $approveQueue->user_id)
                ->where('status', $approveQueue->type)->where('external_id', $approveQueue->product_id)
                ->first();
        }

        $approveQueue->delete();

        if ($notification) {
            $history = ProductEditHistory::where('notification_id', $notification->id)->first();
            if ($history) {
                $history->update([
                    'status' => 'rejected',
                    'approver_id' => auth()->id()
                ]);
            }
            $notification->delete();
        }
        return new ResponseResource(true, "Reject successfully", null);
    }

    public function get_approve_spv($status, $external_id)
    {
        // if ($status == 'inventory') {
        //     $dataOld = New_product::where('id', $external_id)->first();
        //     $dataNew = ApproveQueue::where('product_id', $dataOld->id)
        //         // ->where('type', 'inventory')->whereNot('status', '0')
        //         ->first();
        //         // ->where('type', 'inventory')
        //         // ->latest()
        //         // ->first();

        //     if (!$dataOld || !$dataNew) {
        //         return (new ResponseResource(false, "data not found", null))->response()->setStatusCode(404);
        //     }

        //     $dataNewCustom = [
        //         'user' => $dataNew->user->username,
        //         'product_id' => $dataNew->product_id,
        //         'barcode' => $dataOld->new_barcode_product,
        //         'type' => $dataNew->type,
        //         'code_document' => $dataNew->code_document,
        //         'old_price_product' => $dataNew->old_price_product,
        //         'new_name_product' => $dataNew->new_name_product,
        //         'new_quantity_product' => $dataNew->new_quantity_product,
        //         'new_price_product' => $dataNew->new_price_product,
        //         'new_discount' => $dataNew->new_discount,
        //         'new_tag_product' => $dataNew->new_tag_product,
        //         'new_category_product' => $dataNew->new_category_product,
        //         'created_at' => $dataNew->created_at,
        //         'updated_at' => $dataNew->updated_at,
        //         'deleted_at' => $dataNew->deleted_at,
        //     ];

        //     return new ResponseResource(
        //         true,
        //         "approved edit product",
        //         [
        //             'id' => $dataNew->id,
        //             'dataOld' => $dataOld,
        //             'dataNew' => $dataNewCustom,
        //         ]
        //     );
        // }

        if ($status == 'inventory') {

            // Untuk inventory, external_id dianggap sebagai notification_id
            $dataNew = ApproveQueue::with(['user', 'notification'])
                ->where('notification_id', $external_id)
                ->where('type', 'inventory')
                ->first();

            if (!$dataNew) {
                return (new ResponseResource(false, "data not found", null))
                    ->response()
                    ->setStatusCode(404);
            }

            $dataOld = New_product::find($dataNew->product_id);

            if (!$dataOld) {
                return (new ResponseResource(false, "product not found", null))
                    ->response()
                    ->setStatusCode(404);
            }

            $dataNewCustom = [
                'user' => $dataNew->user->username,
                'product_id' => $dataNew->product_id,
                'barcode' => $dataOld->new_barcode_product,
                'type' => $dataNew->type,
                'code_document' => $dataNew->code_document,
                'old_price_product' => $dataNew->old_price_product,
                'new_name_product' => $dataNew->new_name_product,
                'new_quantity_product' => $dataNew->new_quantity_product,
                'new_price_product' => $dataNew->new_price_product,
                'new_discount' => $dataNew->new_discount,
                'new_tag_product' => $dataNew->new_tag_product,
                'new_category_product' => $dataNew->new_category_product,
                'created_at' => $dataNew->created_at,
                'updated_at' => $dataNew->updated_at,
                'deleted_at' => $dataNew->deleted_at,
            ];

            return new ResponseResource(
                true,
                "approved edit product",
                [
                    'id' => $dataNew->id,
                    'notification' => $dataNew->notification,
                    'dataOld' => $dataOld,
                    'dataNew' => $dataNewCustom,
                ]
            );
        }
        if ($status == 'sale') {
            $check_approved = SaleDocument::where('id', $external_id)
                ->where('approved', '1')
                ->exists();

            if (!$check_approved) {
                return (new ResponseResource(false, "Document is not approved", null))->response()->setStatusCode(404);
            }

            $data = SaleDocument::select(
                'id',
                'code_document_sale',
                'new_discount_sale',
                'total_product_document_sale',
                'total_price_document_sale',
                'total_display_document_sale',
                'voucher',
                'approved',
                'created_at'
            )->where('id', $external_id)
                ->with([
                    'sales' => function ($query) {
                        $query->whereColumn('product_price_sale', '<', 'display_price')
                            ->select(
                                'id',
                                'code_document_sale',
                                'product_name_sale',
                                'product_old_price_sale',
                                'product_category_sale',
                                'product_barcode_sale',
                                'product_price_sale',
                                'product_qty_sale',
                                'total_discount_sale',
                                'created_at',
                                'new_discount_sale',
                                'display_price',
                                'code_document',
                                'approved'
                            );
                    }
                ])->first();
            return new ResponseResource(true, "approved invoice discount", $data);
        }
        if ($status == 'staging') {
            $dataOld = StagingProduct::where('id', $external_id)->first();
            $dataNew = ApproveQueue::where('product_id', $dataOld->id)->whereNot('status', '0')
                ->where('type', 'staging')
                ->first();
            if (!$dataOld || !$dataNew) {
                return (new ResponseResource(false, "data not found", null))->response()->setStatusCode(404);
            }
            $dataNewCustom = [
                'user' => $dataNew->user->username,
                'product_id' => $dataNew->product_id,
                'barcode' => $dataOld->new_barcode_product,
                'type' => $dataNew->type,
                'code_document' => $dataNew->code_document,
                'old_price_product' => $dataNew->old_price_product,
                'new_name_product' => $dataNew->new_name_product,
                'new_quantity_product' => $dataNew->new_quantity_product,
                'new_price_product' => $dataNew->new_price_product,
                'new_discount' => $dataNew->new_discount,
                'new_tag_product' => $dataNew->new_tag_product,
                'new_category_product' => $dataNew->new_category_product,
                'created_at' => $dataNew->created_at,
                'updated_at' => $dataNew->updated_at,
                'deleted_at' => $dataNew->deleted_at,
            ];
            return new ResponseResource(
                true,
                "approved edit product",
                [
                    'id' => $dataNew->id,
                    'dataOld' => $dataOld,
                    'dataNew' => $dataNewCustom,
                ]
            );
        }
    }
}
