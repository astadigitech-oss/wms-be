<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->enum('status', ['progress', 'done'])->default('progress')->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
