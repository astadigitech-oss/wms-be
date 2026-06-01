<?php

namespace App\Http\Controllers\CardInfo;

use App\Http\Controllers\Controller;
use App\Services\AdjustMovementService;
use Illuminate\Http\Request;

class DataController extends Controller
{
    public function stagingSaldoBaru()
    {
        $result = AdjustMovementService::getStagingSaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo staging berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }

    public function displaySaldo()
    {
        $result = AdjustMovementService::getDisplaySaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo display berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }

    public function displayColorSaldo()
    {
        $result = AdjustMovementService::getDisplayColorSaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo display color berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }
}
