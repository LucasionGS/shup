<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the download-limit column for share links, and the indexes the dashboard
 * and the expiry sweep have always needed.
 *
 * Every dashboard listing filters by user_id and most order by created_at, and
 * `shup:expired` scans `expires` every minute — all of which were full table
 * scans. The newer `directories` table already indexes these columns; the older
 * tables never caught up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedInteger('max_downloads')->nullable()->after('downloads');
            $table->index('user_id');
            $table->index('expires');
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('short_urls', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('expires');
        });

        Schema::table('paste_bins', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('expires');
        });

        Schema::table('upload_links', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('expires');
            $table->index('used');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['expires']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('max_downloads');
        });

        Schema::table('short_urls', function (Blueprint $table) {
            $table->dropIndex(['expires']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('paste_bins', function (Blueprint $table) {
            $table->dropIndex(['expires']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('upload_links', function (Blueprint $table) {
            $table->dropIndex(['used']);
            $table->dropIndex(['expires']);
            $table->dropIndex(['user_id']);
        });
    }
};
