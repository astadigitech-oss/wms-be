<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->integer('total_product_bulky')
                ->default(0)
                ->change();

            $table->decimal('total_old_price_bulky', 15, 2)
                ->default(0)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->integer('total_product_bulky')
                ->default(null)
                ->change();

            $table->decimal('total_old_price_bulky', 15, 2)
                ->default(null)
                ->change();
        });
    }
};
