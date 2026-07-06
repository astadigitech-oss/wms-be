<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FixWmsValidationController extends Controller
{
    public function fixValidation(Request $request)
    {
        DB::beginTransaction();

        try {
            /**
             * Mapping nomor yang salah ke nomor yang benar
             */            $mapping = [
                'LQDSLE02881' => 'LQDSLE02876',
                'LQDSLE02876' => 'LQDSLE02877',
                'LQDSLE02877' => 'LQDSLE02878',
                'LQDSLE02878' => 'LQDSLE02879',
                'LQDSLE02879' => 'LQDSLE02880',
                'LQDSLE02880' => 'LQDSLE02881',
                'LQDSLE02883' => 'LQDSLE02882',
                'LQDSLE02882' => 'LQDSLE02883',
            ];

            /**
             * STEP 1
             * Ubah menjadi kode sementara agar tidak bentrok
             */
            foreach ($mapping as $old => $new) {

                DB::table('sale_documents')
                    ->where('code_document_sale', $old)
                    ->update([
                        'code_document_sale' => 'TMP_' . $old
                    ]);
            }

            /**
             * STEP 2
             * Ubah menjadi nomor yang benar
             */
            foreach ($mapping as $old => $new) {

                DB::table('sale_documents')
                    ->where('code_document_sale', 'TMP_' . $old)
                    ->update([
                        'code_document_sale' => $new
                    ]);
            }

            DB::commit();

            return new ResponseResource(
                true,
                'Berhasil memperbaiki nomor validasi.',
                null
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return new ResponseResource(
                false,
                $e->getMessage(),
                null
            );
        }
    }
}
