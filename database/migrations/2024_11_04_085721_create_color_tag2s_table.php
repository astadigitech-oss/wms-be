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
        Schema::create('color_tag2s', function (Blueprint $table) {
            $table->id();
            $table->string('hexa_code_color');
            $table->string('name_color');
            $table->decimal('min_price_color', 15, 2); 
            $table->decimal('max_price_color', 15, 2); 
            $table->decimal('fixed_price_color', 15, 2); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_tag2s');
    }
};
