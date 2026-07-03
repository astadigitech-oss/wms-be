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
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->string('code_document_inbound')->nullable()->after('code_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->dropColumn('code_document_inbound');
        });
    }
};
