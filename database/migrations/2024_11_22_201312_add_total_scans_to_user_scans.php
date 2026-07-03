<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_scans', function (Blueprint $table) {
            $table->integer('total_scans')->nullable();
            $table->date('scan_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_scans', function (Blueprint $table) {
            $table->dropColumn(['total_scans', 'scan_date']); 
        });
    }
};
