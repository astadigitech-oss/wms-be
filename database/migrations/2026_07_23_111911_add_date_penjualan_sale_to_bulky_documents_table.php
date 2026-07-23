<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->timestamp('date_penjualan_sale')
                ->nullable()
                ->after('is_sale');
        });
    }

    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropColumn('date_penjualan_sale');
        });
    }
};
