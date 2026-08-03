<?php

namespace App\Services\Pos;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PosService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.pos.url'), '/');
        $this->clientId = config('services.pos.client_id');
        $this->clientSecret = config('services.pos.client_secret');
    }

    /**
     * 1. Get OAuth Token
     */
    // public function getToken()
    // {

    //     return Cache::remember('pos_oauth_token', 3300, function () {

    //         $response = Http::post($this->baseUrl . '/api/oauth/token', [
    //             'client_id'     => $this->clientId,
    //             'client_secret' => $this->clientSecret,
    //         ]);

    //         $data = $response->json();

    //         if ($response->successful() && !empty($data['access_token'])) {
    //             return $data['access_token'];
    //         }

    //         Log::error('POS Token Error: ' . $response->body());
    //         throw new \Exception('Gagal Get Token POS. Response Server: ' . $response->body());
    //     });
    // }

     public function getToken()
    {

        return Cache::remember('pos_oauth_token', 3300, function () {

            $response = Http::post($this->baseUrl . '/api/oauth/token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            $data = $response->json();
            $accessToken = $data['access_token'] ?? $data['data']['access_token'] ?? null;

            if (!empty($accessToken)) {
                if (!$response->successful()) {
                    Log::warning('POS Token response returned non-2xx status but contained access_token', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                }
                return $accessToken;
            }

            Log::error('POS Token Error: ' . $response->body());
            throw new \Exception('Gagal Get Token POS. Response Server: ' . $response->body());
        });
    }

    /**
     * 2. Get List Stores / Destination Tokens
     */
    public function getStores()
    {
        $token = $this->getToken();
        $endpoint = $this->baseUrl . '/api/destination-stores/sync';

        $response = Http::withToken($token)->get($endpoint);

        if ($response->status() === 401) {
            Cache::forget('pos_oauth_token');
            $token = $this->getToken();

            $response = Http::withToken($token)->get($endpoint);
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Gagal mengambil list store dari POS: ' . $response->body());
        throw new \Exception('Gagal mengambil data toko dari server POS.');
    }

    public function getStoreTokenByShopName(string $shopName): ?string
    {
        $response = $this->getStores();
        $stores = $response['resource'] ?? $response['data'] ?? [];

        if (!is_array($stores)) {
            return null;
        }

        foreach ($stores as $store) {
            $storeName = $store['store_name'] ?? $store['name'] ?? null;
            $storeToken = $store['token'] ?? $store['store_token'] ?? null;

            if ($storeName !== null && strcasecmp(trim($storeName), trim($shopName)) === 0) {
                return $storeToken ?: null;
            }
        }

        return null;
    }

    /**
     * 3. Send Batch Products to POS
     */
    public function sendBatchProducts($documentCode, $storeToken, $products)
    {
        $token = $this->getToken();
        $endpoint = $this->baseUrl . '/api/products/store';
        $payload = [
            "document_code" => $documentCode,
            "store_token"   => $storeToken,
            "products"      => $products
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($endpoint, $payload);

        if ($response->status() === 401) {
            Cache::forget('pos_oauth_token');
            Log::info("Token POS Expired saat mengirim batch {$documentCode}. Meminta token baru dan mengulang otomatis...");

            $token = $this->getToken();

            $response = Http::withToken($token)
                ->acceptJson()
                ->post($endpoint, $payload);
        }

        if ($response->successful()) {
            return $response->json();
        }

        Log::error("Gagal mengirim batch Dokumen {$documentCode}: " . $response->body());
        throw new \Exception("Gagal mengirim batch produk ke POS. Status: " . $response->status() . " | Pesan: " . $response->body());
    }

    /**
     * 4. Delete BKL Products
     */
    public function deleteBklProducts(array $barcodes)
    {
        $token = $this->getToken();
        $endpoint = $this->baseUrl . '/api/products-bkl';
        $payload = [
            "product_barcode" => $barcodes
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->delete($endpoint, $payload);

        if ($response->status() === 401) {
            Cache::forget('pos_oauth_token');
            Log::info("Token POS Expired saat hapus BKL. Meminta token baru dan mengulang otomatis...");

            $token = $this->getToken();

            $response = Http::withToken($token)
                ->acceptJson()
                ->delete($endpoint, $payload);
        }

        if ($response->successful()) {
            return $response->json();
        }

        $errorBody = $response->json();

        $errorMessage = $errorBody['message'] ?? 'Gagal menghapus produk di POS.';
        $errorData = $errorBody['data'] ?? [];

        Log::error("Gagal menghapus produk BKL di POS", [
            'status'   => $response->status(),
            'response' => $errorBody
        ]);

        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => $errorMessage,
            'data'    => $errorData
        ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 500));
    }
}
