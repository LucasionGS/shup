<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing store for resumable uploads.
 *
 * A single-request upload has to fit inside upload_max_filesize, post_max_size
 * and max_execution_time all at once, and a connection that drops at 90% of a
 * large file starts again from zero. A session records how much of the file has
 * already landed so the client can carry on from that offset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedBigInteger('received_size')->default(0);
            $table->string('storage_path');

            // Carried through to the File once the upload completes.
            $table->string('password')->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('expires_minutes')->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
