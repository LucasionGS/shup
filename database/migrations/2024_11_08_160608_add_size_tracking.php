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
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('size')->default(0)->after('mime_type');
        });

        Schema::table('paste_bins', function (Blueprint $table) {
            $table->unsignedBigInteger('size')->default(0)->after('password');
        });

        Schema::table('short_urls', function (Blueprint $table) {
            $table->unsignedBigInteger('size')->default(0)->after('url');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_used')->default(0)->after('email_verified_at');
            $table->unsignedBigInteger('storage_limit')->default(0)->after('storage_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('paste_bins', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('short_urls', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('storage_used');
            $table->dropColumn('storage_limit');
        });
    }
};
