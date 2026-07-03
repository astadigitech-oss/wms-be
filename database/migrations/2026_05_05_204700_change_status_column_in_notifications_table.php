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
        DB::statement("ALTER TABLE notifications MODIFY COLUMN status VARCHAR(50)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('status', ['manual_inventory', 'manual_staging'])
            ->delete(); 

        DB::statement("ALTER TABLE notifications MODIFY COLUMN status ENUM('pending', 'done', 'inventory', 'staging', 'sale', 'palet', 'display')");
    }
};