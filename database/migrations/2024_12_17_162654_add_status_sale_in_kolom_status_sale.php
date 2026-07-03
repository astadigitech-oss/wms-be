<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Menambahkan nilai 'sale' ke ENUM kolom 'status'
        DB::statement("ALTER TABLE notifications MODIFY status ENUM('pending', 'done', 'staging', 'display', 'sale') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Mengembalikan ENUM ke nilai sebelumnya
        DB::statement("ALTER TABLE notifications MODIFY status ENUM('pending', 'done', 'staging', 'display') NOT NULL DEFAULT 'pending'");
    }
};
