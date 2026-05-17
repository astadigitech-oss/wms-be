<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Repair;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\ProductApprove;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\Document;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;


class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');

        $notifications = Notification::latest()
            ->when($query, function ($q) use ($query) {
                return $q->where('notification_name', 'LIKE', '%' . $query . '%');
            })
            ->paginate(100);

        return new ResponseResource(true, "list notification", $notifications);
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
    public function show(Notification $notification)
    {
        if (!$notification) {
            return new ResponseResource(false, "id notification tidak terdaftar", null);
        }
        return new ResponseResource(true, "detail notification", $notification);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Notification $notification)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Notification $notification)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            $resource = new ResponseResource(false, "User tidak dikenali", null);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'notification_name' => 'required',
            'status' => 'required|in:pending,done'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }


        $notification->update([
            'notification_name' => $request->notification_name,
            'status' => $request->status
        ]);
        return new ResponseResource(true, "berhasil update", $notification);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Notification $notification)
    {
        try {
            $notification->delete();
            return new ResponseResource(true, "berhasil di hapus", null);
        } catch (\Exception $e) {
            return response()->json(["error" => $e], 402);
        }
    }

    public function approveTransaction($notificationId)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $user = User::with('role')->find(auth()->id());

        DB::beginTransaction();

        try {
            if ($user && ($user->role && ($user->role->role_name == 'Spv' ||  $user->role->role_name == 'Admin'))) {
                $notification = Notification::find($notificationId);

                if (!$notification) {
                    return response()->json(['error' => 'Transaksi tidak ditemukan'], 404);
                }

                if ($notification->status == 'staging' || $notification->status == 'done') {
                    return response()->json(['message' => 'Transaksi sudah disetujui sebelumnya'], 422);
                }

                $notification->update([
                    'notification_name' => 'Approved',
                    'status' => 'done',
                ]);

                if ($notification->riwayat_check_id !== null) {
                    $riwayatCheck = RiwayatCheck::find($notification->riwayat_check_id);
                    $document = Document::where('code_document', $riwayatCheck->code_document)->first();
                    $document->update([
                        'status_document' => 'done'
                    ]);

                    if ($riwayatCheck) {
                        $riwayatCheck->update(['status_approve' => 'done']);

                        $productApprovesTags = ProductApprove::where('code_document', $riwayatCheck->code_document)
                            ->whereNotNull('new_tag_product')
                            ->get();

                        $productApprovesCategories = ProductApprove::where('code_document', $riwayatCheck->code_document)
                            ->whereNull('new_tag_product')
                            ->get();

                        // Fungsi untuk insert dan hapus data
                        $this->processProductApproves($productApprovesTags, New_product::class, 100);
                        $this->processProductApproves($productApprovesCategories, StagingProduct::class, 200);

                        // Menangani RepairCheck jika ada
                        $repairCheck = Repair::where('user_id', $notification->user_id)->first();

                        if ($repairCheck) {
                            $repairCheck->update(['status_approve' => 'done']);

                            $repairCheck->repair_products()->chunkById(200, function ($productFilter) {
                                foreach ($productFilter as $product) {
                                    New_product::create([
                                        'code_document' => $product->code_document,
                                        'old_barcode_product' => $product->old_barcode_product,
                                        'new_barcode_product' => $product->new_barcode_product,
                                        'new_name_product' => $product->new_name_product,
                                        'new_quantity_product' => $product->new_quantity_product,
                                        'new_price_product' => $product->new_price_product,
                                        'new_date_in_product' => $product->new_date_in_product,
                                        'new_status_product' => 'display',
                                        'new_quality' => $product->new_quality,
                                        'new_category_product' => $product->new_category_product,
                                        'new_tag_product' => $product->new_tag_product,
                                        'new_discount' => $product->new_discount,
                                        'display_price' => $product->display_price,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                        'is_so' => null
                                    ]);

                                    $product->delete();
                                }
                            });

                            // Setelah semua produk terkait dihapus, hapus repairCheck
                            $repairCheck->delete();
                        }
                    }
                }

                DB::commit();
                return new ResponseResource(true, 'Transaksi berhasil diapprove', $notification);
            } else {
                $response = new ResponseResource(false, "User tidak diizinkan atau role tidak valid", null);
                return $response->response()->setStatusCode(403);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal mengapprove transaksi", $e->getMessage());
        }
    }

    private function processProductApproves($productApproves, $modelClass, $chunkSize)
    {
        $productApproves->chunk($chunkSize)->each(function ($chunk) use ($modelClass) {
            $dataToInsert = [];

            foreach ($chunk as $productApprove) {
                $dataToInsert[] = [
                    'code_document' => $productApprove->code_document,
                    'old_barcode_product' => $productApprove->old_barcode_product,
                    'new_barcode_product' => $productApprove->new_barcode_product,
                    'new_name_product' => $productApprove->new_name_product,
                    'new_quantity_product' => $productApprove->new_quantity_product,
                    'new_price_product' => $productApprove->new_price_product,
                    'old_price_product' => $productApprove->old_price_product,
                    'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                    'new_status_product' => $productApprove->new_status_product,
                    'new_quality' => $productApprove->new_quality,
                    'new_category_product' => $productApprove->new_category_product,
                    'new_tag_product' => $productApprove->new_tag_product,
                    'new_discount' => $productApprove->new_discount,
                    'display_price' => $productApprove->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insert ke model yang ditentukan
            $modelClass::insert($dataToInsert);

            // Hapus data setelah berhasil insert
            ProductApprove::destroy($chunk->pluck('id'));
        });
    }

    public function getNotificationByRole(Request $request)
    {
        $userId = auth()->id();
        $userRole = User::where('id', $userId)->with('role')->first();

        $query = $request->input('search') ?? $request->input('q');

        $page = $request->input('page', 1);
        $perPage = 30;

        $notifQuery = Notification::query()
            ->latest('notifications.created_at');

        if (!in_array($userRole->role->id, [1, 2, 5, 8])) {
            $notifQuery->whereNot('status', 'sale');
        }

        if (!empty($query)) {
            $notifQuery->where(function ($qBuilder) use ($query) {
                $qBuilder->where('status', 'LIKE', '%' . $query . '%')
                    ->orWhere('notification_name', 'LIKE', '%' . $query . '%');
            });
        }


        $notifications = $notifQuery->paginate($perPage);

        return new ResponseResource(true, "Notifications", $notifications);
    }

    public function notifWidget(Request $request)
    {
        $userId = auth()->id();
        $userRole = User::where('id', $userId)->with('role')->first();
        $query = $request->input('q');

        $notifQuery = Notification::query()->latest()->limit(5);

        if (!in_array($userRole->role->id, [1, 2, 5, 8])) {
            $notifQuery->whereNot('status', 'sale');
        }
        // Jika ada query pencarian
        if ($query) {
            $notifQuery->where('status', 'LIKE', '%' . $query . '%');
        }

        // Ambil hasil query
        $notifications = $notifQuery->get();

        // Kembalikan hasil dalam format ResponseResource
        return new ResponseResource(true, "Notifications", $notifications);
    }

    public function actionManualProduct(Request $request, $notificationId)
    {
        $user = User::with('role')->find(auth()->id());
        $roleName = $user->role->role_name ?? '';

        if (!in_array($roleName, ['Admin', 'Spv'])) {
            $response = new ResponseResource(false, "User tidak diizinkan", null);
            return $response->response()->setStatusCode(403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $notification = Notification::find($notificationId);

            if (!$notification || $notification->status !== 'manual_inbound') {
                return response()->json(['message' => 'Notifikasi tidak valid'], 404);
            }

            $barcode = str_replace('Approval Manual Product: ', '', $notification->notification_name);

            $product = \App\Models\New_product::where('id', $notification->external_id)
                ->where('new_barcode_product', $barcode)
                ->first();

            if (!$product) {
                $product = \App\Models\StagingProduct::where('id', $notification->external_id)
                    ->where('new_barcode_product', $barcode)
                    ->first();
            }

            if (!$product) {
                $notification->delete();
                return response()->json(['message' => 'Produk asli sudah tidak ditemukan'], 404);
            }

            if ($request->action === 'approve') {
                $product->update(['is_pending' => false]);

                $qualityData = json_decode($product->new_quality, true);
                $inputData = $product->toArray();

                app(NewProductController::class)->incrementStockOpname($inputData, $qualityData);

                $notification->update([
                    'notification_name' => 'Approved - ' . $notification->notification_name,
                    'status' => 'manual_inbound',
                    'approved' => '2'
                ]);

                $message = "Produk berhasil di-approve.";
            } else {
                $product->delete();

                $notification->update([
                    'notification_name' => 'Rejected - ' . $notification->notification_name,
                    'status' => 'manual_inbound',
                    'approved' => '1'
                ]);

                $message = "Produk ditolak dan data telah dihapus.";
            }

            DB::commit();
            return new ResponseResource(true, $message, null);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function detailManualProduct($notificationId)
    {
        try {
            $notification = Notification::find($notificationId);

            if (!$notification || $notification->status !== 'manual_inbound') {
                return response()->json(['message' => 'Notifikasi tidak valid atau tidak ditemukan'], 404);
            }

            $barcode = str_replace('Approval Manual Product: ', '', $notification->notification_name);

            $product = \App\Models\New_product::with('user')->where('id', $notification->external_id)
                ->where('new_barcode_product', $barcode)
                ->first();

            $type = 'Color';

            if (!$product) {
                $product = \App\Models\StagingProduct::with('user')->where('id', $notification->external_id)
                    ->where('new_barcode_product', $barcode)
                    ->first();
                $type = 'Reguler';
            }

            if (!$product) {
                $notification->delete();
                return response()->json(['message' => 'Detail produk asli sudah tidak ditemukan di database'], 404);
            }

            $dataResponse = [
                'notification_id' => $notification->id,
                'role' => $notification->role,
                'name' => $product->user ? $product->user->name : 'Unknown',
                'type' => $type,
                'is_pending' => $product->is_pending,
                'detail_product' => [
                    'old_price_product' => $product->old_price_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'new_status_product' => $product->new_status_product,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_quality' => json_decode($product->new_quality, true),
                    'created_at' => $product->created_at
                ]
            ];

            return new ResponseResource(true, "Detail approval produk manual", $dataResponse);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function detailPendingApproval($notificationId)
    {
        try {
            $notification = Notification::find($notificationId);

            if (!$notification || $notification->status !== 'pending_approval') {
                return response()->json(['message' => 'Notifikasi tidak valid atau tidak ditemukan'], 404);
            }

            $barcode = str_replace('Approval Perubahan Data: ', '', $notification->notification_name);

            $productApprove = \App\Models\ProductApprove::where('new_barcode_product', $barcode)->first();

            if (!$productApprove) {
                return response()->json(['message' => 'Data produk pending tidak ditemukan'], 404);
            }

            $productOld = \App\Models\Product_old::where('old_barcode_product', $productApprove->old_barcode_product)
                ->where('code_document', $productApprove->code_document)
                ->first();

            $dataResponse = [
                'notification_id' => $notification->id,
                'notification_name' => $notification->notification_name,
                'requested_by' => \App\Models\User::find($notification->user_id)->name ?? 'Unknown',
                'status_notif' => $notification->status,
                'comparison_data' => [
                    'old_data' => [
                        'old_name_product' => $productOld ? $productOld->old_name_product : 'Data tidak ditemukan',
                        'old_quantity_product' => $productOld ? $productOld->old_quantity_product : 0,
                        'old_price_product' => $productOld ? $productOld->old_price_product : 0,
                    ],
                    'new_data' => [
                        'new_barcode_product' => $productApprove->new_barcode_product,
                        'new_name_product' => $productApprove->new_name_product,
                        'new_quantity_product' => $productApprove->new_quantity_product,
                        'new_price_product' => $productApprove->new_price_product,
                        'new_category_product' => $productApprove->new_category_product,
                        'new_tag_product' => $productApprove->new_tag_product,
                        'new_status_product' => $productApprove->new_status_product,
                        'new_quality' => json_decode($productApprove->new_quality, true),
                    ]
                ],
                'created_at' => $notification->created_at
            ];

            return new ResponseResource(true, "Detail perbandingan data untuk approval", $dataResponse);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function actionProductApproval(Request $request, $notificationId)
    {
        $user = \App\Models\User::with('role')->find(auth()->id());
        $roleName = $user->role->role_name ?? '';

        if (!in_array($roleName, ['Admin', 'Spv'])) {
            $response = new \App\Http\Resources\ResponseResource(false, "User tidak diizinkan", null);
            return $response->response()->setStatusCode(403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'action' => 'required|in:approve,reject'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $notification = Notification::find($notificationId);

            if (!$notification || $notification->status !== 'pending_approval') {
                return response()->json(['message' => 'Notifikasi tidak valid atau bukan pending approval'], 404);
            }

            $barcode = str_replace('Approval Perubahan Data: ', '', $notification->notification_name);
            $productApprove = \App\Models\ProductApprove::where('new_barcode_product', $barcode)->first();

            if (!$productApprove) {
                $notification->delete();
                return response()->json(['message' => 'Data produk pending sudah tidak ditemukan'], 404);
            }

            if ($request->action === 'approve') {
                $productApprove->update(['is_pending' => false]);

                DB::statement(
                    "DELETE FROM product_olds WHERE code_document = ? AND old_barcode_product = ? LIMIT 1",
                    [$productApprove->code_document, $productApprove->old_barcode_product]
                );

                $notification->update([
                    'notification_name' => 'Approved - ' . str_replace('Approval Perubahan Data: ', '', $notification->notification_name),
                    'status' => 'pending_approval',
                    'approved' => '2'
                ]);

                $message = "Perubahan produk disetujui.";
            } else {
                $productApprove->delete();

                $notification->update([
                    'notification_name' => 'Rejected - ' . str_replace('Approval Perubahan Data: ', '', $notification->notification_name),
                    'status' => 'pending_approval',
                    'approved' => '1'
                ]);

                $message = "Perubahan produk ditolak dan data dibatalkan.";
            }

            DB::commit();
            return new \App\Http\Resources\ResponseResource(true, $message, null);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
