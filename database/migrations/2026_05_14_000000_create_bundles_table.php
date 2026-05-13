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
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->string('short_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('expires')->nullable();
            $table->foreignId('user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 20);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'resource_type', 'resource_id']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
    }
};