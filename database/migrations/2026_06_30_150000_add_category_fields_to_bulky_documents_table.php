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
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->foreignId('category_bulky_id')->nullable()->after('category_bulky');
            $table->string('category_bulky_name')->nullable()->after('category_bulky_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropColumn(['category_bulky_id', 'category_bulky_name']);
        });
    }
};
