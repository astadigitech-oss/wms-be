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
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->integer('cardbox_qty')->nullable()->after('status_document_sale');
            $table->decimal('cardbox_unit_price', 15, 2)->nullable()->after('cardbox_qty');
            $table->decimal('cardbox_total_price', 15, 2)->nullable()->after('cardbox_unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->dropColumn('cardbox_qty');
            $table->dropColumn('cardbox_unit_price');
            $table->dropColumn('cardbox_total_price');
        });
    }
};
