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
            Schema::table('palet_filters', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users');
            });
            Schema::table('product__filters', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users');
            });
            Schema::table('repair_filters', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
            Schema::table('palet_filters', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
            Schema::table('product__filters', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
            Schema::table('repair_filters', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
};
