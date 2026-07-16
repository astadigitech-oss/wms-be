<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_rack_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('new_product_id')->nullable()->after('color_rack_id');
            $table->unsignedBigInteger('bundle_id')->nullable()->after('new_product_id');
            $table->string('source_type')->nullable()->after('bundle_id');
            $table->string('source_key')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('color_rack_histories', function (Blueprint $table) {
            $table->dropColumn(['new_product_id', 'bundle_id', 'source_type', 'source_key']);
        });
    }
};
