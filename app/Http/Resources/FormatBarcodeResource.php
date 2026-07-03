<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormatBarcodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    // public function toArray($request)
    // {
    //     return [
    //         'id' => $this->id,
    //         'format' => $this->format,
    //         'total_user' => $this->total_user,
    //         'total_scan' => $this->total_scan,
    //         'created_at' => $this->created_at,
    //         'updated_at' => $this->updated_at,
    //         'users' => $this->users->map(function ($user) {
    //             return [
    //                 'id' => $user->id,
    //                 'username' => $user->username,
    //                 'email' => $user->email,
    //                 'role_name'  => $user->role->role_name,
    //                 'total_scan' => $user->user_scans->sum('total_scans'),
    //                 'scan_today' => $user->user_scans
    //                     ->filter(function ($scan) {
    //                         return Carbon::parse($scan->scan_date)->isToday();
    //                     })->sum('total_scans'),
    //                 'user_scans' => $user->user_scans->map(function ($scan) {
    //                     return [
    //                         'id' => $scan->id,
    //                         'total_scans' => $scan->total_scans,
    //                         'scan_date' => $scan->scan_date,
    //                     ];
    //                 }),
    //             ];
    //         }),
    //     ];
    // }
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'format' => $this->format,
            'total_user' => $this->total_user,
            'total_scan' => $this->total_scan,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'users' => $this->user_scans->map(function ($scan) {
                return [
                    'id' => $scan->user->id,
                    'username' => $scan->user->username,
                    'email' => $scan->user->email,
                    'role_name' => $scan->user->role->role_name ?? null,
                    'total_scans' => $scan->total_scans,
                    // Hanya memeriksa scan_date apakah sesuai dengan hari ini
                    'scan_today' => Carbon::parse($scan->scan_date)->isToday() ? $scan->total_scans : 0,
                    'scan_date' => $scan->scan_date,
                ];
            }),
        ];
    }
}
