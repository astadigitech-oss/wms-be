<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('approved', ['0', '1', '2'])->nullable()->default('0');
        });
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->enum('approved', ['0', '1', '2'])->nullable()->default('0');
        });
    }
  
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
    }
};
