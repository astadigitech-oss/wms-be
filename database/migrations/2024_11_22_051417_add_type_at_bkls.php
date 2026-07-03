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
        Schema::table('bkls', function (Blueprint $table) {
            $table->enum('type', ['type1', 'type2'])->nullable();
        });
        Schema::table('filter_bkls', function (Blueprint $table) {
            $table->enum('type', ['type1', 'type2'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bkls', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::table('filter_bkls', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
