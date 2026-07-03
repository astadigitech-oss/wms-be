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
         Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'format_barcode')) {
                $table->dropColumn('format_barcode');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('format_barcode_id')
                ->nullable() 
                ->constrained('format_barcodes') 
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

         Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'format_barcode_id')) {
                $table->dropForeign(['format_barcode_id']);
                $table->dropColumn('format_barcode_id');
            }
        });
    }
};
