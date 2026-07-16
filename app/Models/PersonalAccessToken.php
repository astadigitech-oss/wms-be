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

            // Cache::add HANYA akan menghasilkan true jika key BELUM ADA di cache (Operasi Atomik)
            // Jika key SUDAH ADA, dia langsung return false.
            if (! Cache::add($cacheKey, true, now()->addMinutes(5))) {
                return true; // Skip update ke MySQL jika gagal menambah cache
            }
            // // Jika yang berubah HANYA kolom last_used_at, potong kompas dan return true (jangan kirim query ke MySQL)
            // if ($this->isDirty('last_used_at') && ! $this->isDirty(['token', 'abilities', 'name'])) {
            //     return true; 
            // }
        }

        return parent::save($options);
    }
}
