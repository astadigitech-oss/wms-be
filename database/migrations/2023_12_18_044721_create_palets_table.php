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
        Schema::create('palets', function (Blueprint $table) {
            $table->id();
            $table->string('name_palet');
            $table->string('category_palet');
            $table->decimal('total_price_palet', 15, 2);
            $table->integer('total_product_palet');
            $table->string('palet_barcode');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('palets');
    }
};
