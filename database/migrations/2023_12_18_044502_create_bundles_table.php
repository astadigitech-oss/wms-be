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
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name_bundle')->unique();
            $table->decimal('total_price_bundle', 12, 2);
            $table->decimal('total_price_custom_bundle', 12, 2);
            $table->integer('total_product_bundle');
            $table->enum('product_status', ['not sale', 'sale','bundle']);
            $table->string('barcode_bundle')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
