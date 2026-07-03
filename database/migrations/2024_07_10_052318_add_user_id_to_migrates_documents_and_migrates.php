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
        Schema::table('migrate_documents', function (Blueprint $table) {
            // Menambahkan kolom user_id dan foreign key constraint
            $table->foreignId('user_id')->constrained('users');
        });
        Schema::table('migrates', function (Blueprint $table) {
            // Menambahkan kolom user_id dan foreign key constraint
            $table->foreignId('user_id')->constrained('users');
        });
    }


    public function down(): void
    {
        Schema::table('migrate_documents', function (Blueprint $table) {
            // Menghapus foreign key constraint dan kolom user_id
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
        Schema::table('migrates', function (Blueprint $table) {
            // Menghapus foreign key constraint dan kolom user_id
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
