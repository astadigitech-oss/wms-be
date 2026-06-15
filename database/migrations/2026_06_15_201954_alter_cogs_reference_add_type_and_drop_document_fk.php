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
        Schema::table('cogs_reference', function (Blueprint $table) {
            $table->dropForeign(['document_id']);

            $table->enum('type', ['sku', 'reguler'])
                ->default('reguler');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cogs_reference', function (Blueprint $table) {
            $table->dropColumn('type');

            $table->foreign('document_id')
                ->references('id')
                ->on('documents');
        });
    }
};
