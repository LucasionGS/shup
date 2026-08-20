<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API tokens were stored as plaintext UUIDs and matched with a direct equality
 * lookup, so a database dump (or a backup, or a leaked log) handed over
 * directly usable credentials.
 *
 * This splits the column in two:
 *   - api_token_hash      SHA-256 of the token, used for the lookup. Indexed and
 *                         unique, so authentication stays a single indexed match.
 *   - api_token_encrypted The token encrypted with APP_KEY, so the profile page
 *                         and the ShareX snippets can still display it, but the
 *                         database alone is not enough to recover it.
 *
 * Existing tokens keep working: the hash is derived from the same plaintext, so
 * CLI and ShareX configurations already in the wild continue to authenticate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token_hash', 64)->nullable()->unique()->after('email');
            $table->text('api_token_encrypted')->nullable()->after('api_token_hash');
            $table->timestamp('api_token_last_used_at')->nullable()->after('api_token_encrypted');
        });

        // Backfill from the existing plaintext column.
        DB::table('users')
            ->select('id', 'api_token')
            ->whereNotNull('api_token')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'api_token_hash' => hash('sha256', $user->api_token),
                            'api_token_encrypted' => Crypt::encryptString($user->api_token),
                        ]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['api_token']);
            $table->dropColumn('api_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 80)->unique()->nullable()->default(null)->after('email');
        });

        DB::table('users')
            ->select('id', 'api_token_encrypted')
            ->whereNotNull('api_token_encrypted')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    try {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['api_token' => Crypt::decryptString($user->api_token_encrypted)]);
                    } catch (\Throwable) {
                        // Undecryptable token (rotated APP_KEY); leave it null so
                        // the user simply regenerates one.
                    }
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_token_hash', 'api_token_encrypted', 'api_token_last_used_at']);
        });
    }
};
