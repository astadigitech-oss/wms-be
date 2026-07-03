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
        Schema::table('ppns', function (Blueprint $table) {
            $table->boolean('is_tax_default')->default(false)->comment('Penanda PPN yang aktif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ppns', function (Blueprint $table) {
            $table->dropColumn('is_tax_default');
        });
    }
};
