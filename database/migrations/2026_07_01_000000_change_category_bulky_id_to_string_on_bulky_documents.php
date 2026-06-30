<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->string('category_bulky_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->foreignId('category_bulky_id')->nullable()->change();
        });
    }
};
