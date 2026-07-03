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
            $table->dropForeign(['product_condition_id']);
            $table->dropForeign(['product_status_id']);
            $table->dropForeign(['destination_id']);
            $table->dropColumn(['product_condition_id', 'product_status_id', 'destination_id', 'warehouse', 'condition', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->foreignId('product_condition_id')->constrained();
            $table->foreignId('product_status_id')->constrained();
            $table->foreignId('destination_id')->constrained();
            $table->string('warehouse');
            $table->string('condition');
            $table->string('status');
        });
    }
};
