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
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->string('short_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('expires')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('directory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->index();
            $table->text('path');
            $table->char('path_hash', 64);
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_path')->nullable();
            $table->timestamps();

            $table->unique(['directory_id', 'path_hash']);
            $table->index(['directory_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('directory_items');
        Schema::dropIfExists('directories');
    }
};