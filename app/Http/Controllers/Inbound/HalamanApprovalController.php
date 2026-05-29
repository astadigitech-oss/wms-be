<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\ScanPending;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HalamanApprovalController extends Controller
{
    public function listApprovalBast()
    {
        try {

            $data = ScanPending::select(
                'id',
                'source_id',
                'edited_name',
                'edited_qty',
                'status',
                'editor_id',
                'approver_id',
                'created_at'
            )
                ->where('status', 'pending')
                ->where('source_model', 'Product_old')
                ->with([
                    'editor:id,name',
                    'approver:id,name'
                ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {

                    return [
                        'id' => $item->id,
                        'source_id' => $item->source_id,
                        'edited_name' => $item->edited_name,
                        'edited_qty' => $item->edited_qty,
                        'status' => $item->status,
                        'created_at' => $item->created_at,

                        'editor_name' => $item->editor?->name,
                        'approver_name' => $item->approver?->name,
                    ];
                });

            return new ResponseResource(
                true,
                'List data yang menunggu approval',
                $data
            );
        } catch (\Exception $e) {

            return new ResponseResource(
                false,
                'Gagal mengambil data: ' . $e->getMessage(),
                null
            );
        }
    }

    public function approveBast(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $scanPending = ScanPending::findOrFail($id);

            if ($scanPending->status !== 'pending') {

                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Data sudah diproses sebelumnya.',
                    null
                );
            }

            $scanPending->status = 'approved';
            $scanPending->approver_id = auth()->id();
            $scanPending->approved_at = now();
            $scanPending->save();

            DB::commit();

            return new ResponseResource(
                true,
                'Data berhasil diapprove.',
                null
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return new ResponseResource(
                false,
                'Gagal mengapprove data: ' . $e->getMessage(),
                null
            );
        }
    }

    public function rejectBast(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $scanPending = ScanPending::findOrFail($id);

            if ($scanPending->status !== 'pending') {

                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Data sudah diproses sebelumnya.',
                    null
                );
            }

            $scanPending->status = 'rejected';
            $scanPending->approver_id = auth()->id();
            $scanPending->approved_at = now();
            $scanPending->save();

            DB::commit();

            return new ResponseResource(
                true,
                'Data berhasil direject.',
                null
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return new ResponseResource(
                false,
                'Gagal reject data: ' . $e->getMessage(),
                null
            );
        }
    }
}
