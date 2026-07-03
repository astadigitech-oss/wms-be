<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function ($query) {
            Log::info($query->sql, $query->bindings);
        });

        if (env('APP_ENV') !== 'local') {
            URL::forceScheme('https');
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Perangkap Query Lambat: Catat ke log jika query > 5 detik
        DB::whenQueryingForLongerThan(CarbonInterval::seconds(5), function ($connection) {
            Log::warning("Database waduh lemot banget! Total waktu query: {$connection->totalQueryDuration()}ms");
        });

        // Alternatif: Catat detail query spesifik yang lewat dari 3 detik
        DB::listen(function ($query) {
            if ($query->time > 3000) { // waktu dalam milidetik (3000ms = 3 detik)
                Log::warning("Slow Query Terdeteksi: SQL -> {$query->sql} | Bindings -> " . json_serialize($query->bindings) . " | Waktu -> {$query->time}ms");
            }
        });
    }
}
