<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Override method save bawaan untuk meredam lock contention
     */
    public function save(array $options = [])
    {
        // Jika yang berubah HANYA kolom last_used_at (aktivitas request biasa)
        if ($this->isDirty('last_used_at') && ! $this->isDirty(['token', 'abilities', 'name'])) {

            $cacheKey = 'sanctum_token_throttle:' . $this->id;

            // Jika dalam 5 menit terakhir sudah pernah di-update, skip update ke MySQL
            if (Cache::has($cacheKey)) {
                return true;
            }

            // Jika belum, pasang gembok cache selama 5 menit, lalu biarkan query simpan ke MySQL jalan
            Cache::put($cacheKey, true, now()->addMinutes(5));
        }

        return parent::save($options);
    }
}
