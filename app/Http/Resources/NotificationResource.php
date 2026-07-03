<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request) {
        return [
            'id' => $this->id,
            'notification_name' => $this->notification_name,
            'status' => $this->status,
            'spv_id' => $this->spv_id,
            'riwayat_check_id' => $this->riwayat_check_id,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'role' => [
                'role_name' => $this->user->role->role_name,
            ],
        ];
    }
    
}
              