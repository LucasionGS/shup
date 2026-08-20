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
        Schema::table('upload_links', function (Blueprint $table) {
            $table->boolean('multi_file')->default(false)->after('used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_links', function (Blueprint $table) {
            $table->dropColumn('multi_file');
        });
    }
};
