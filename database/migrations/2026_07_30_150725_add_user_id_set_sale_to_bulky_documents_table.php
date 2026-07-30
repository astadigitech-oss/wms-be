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
            $table->foreignId('user_id_set_sale')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropForeign(['user_id_set_sale']);
            $table->dropColumn('user_id_set_sale');
        });
    }
};
