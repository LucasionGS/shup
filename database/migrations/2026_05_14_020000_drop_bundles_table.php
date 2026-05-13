<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Bundles have been removed in favor of directories.
    }
};