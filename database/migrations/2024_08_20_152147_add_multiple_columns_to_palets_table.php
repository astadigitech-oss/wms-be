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
        Schema::table('palets', function (Blueprint $table) {
            $table->string('file_pdf')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('warehouse')->nullable();
            $table->string('condition')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_sale')->default(false);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->dropColumn([
                'file_pdf',
                'description',
                'is_active',
                'warehouse',
                'condition',
                'status',
                'is_sale'
            ]);
        });
    }
};
