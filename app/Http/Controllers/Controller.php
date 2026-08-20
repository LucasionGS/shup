<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\File;
use App\Models\User;
use App\Support\PasswordCrypto;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Generate a short code using a cryptographically secure RNG.
     *
     * Uses random_int() (CSPRNG) rather than rand() so codes cannot be
     * predicted from observed values, and widens the default to 10 base62
     * characters (~62^10 space). Existing shorter codes still resolve because
     * lookups are exact-match, so widening is backwards compatible.
     */
    public function generateShortcode(int $length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    protected function encryptData(string $content, string $key) {
        return PasswordCrypto::encrypt($content, $key);
    }

    protected function decryptData(string $encryptedData, string $key) {
        return PasswordCrypto::decrypt($encryptedData, $key);
    }

    protected function rejectIfNotAuthenticated(?User $user = null): JsonResponse|null {
        if (!$user && !auth()->check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    /**
     * Per-upload ceiling in kilobytes, for use in `max:` validation rules.
     *
     * Falls back to PHP's own upload_max_filesize so the rule never claims to
     * allow more than the runtime will actually accept.
     */
    protected function maxUploadKilobytes(): int {
        $configured = (int) Configuration::getValue('max_upload_bytes', 0);
        $phpLimit = File::expandPHPFileSize((string) ini_get('upload_max_filesize'));

        $limits = array_filter([$configured, $phpLimit], fn ($value) => $value > 0);
        $bytes = $limits === [] ? $phpLimit : min($limits);

        return max(1, (int) floor($bytes / 1024));
    }

    /**
     * Reject an upload that would push the user past their storage quota.
     *
     * The quota was previously only ever displayed: nothing compared
     * storage_used against storage_limit, so any user could fill the disk.
     * Anonymous uploads have no quota to charge and are left to the per-upload
     * size ceiling instead.
     */
    protected function rejectIfOverQuota(?User $user, int $bytes): JsonResponse|null {
        if (!$user || $user->canStore($bytes)) {
            return null;
        }

        return response()->json([
            'error' => 'Storage quota exceeded.',
            'remaining' => $user->remainingStorage(),
        ], 413);
    }

    protected function rejectIfNotAuthenticatedIfNeeded(?User $user = null): JsonResponse|null {
        if (
            !Configuration::getBool("allow_anonymous_upload", false)
            && $authRes = $this->rejectIfNotAuthenticated($user)
        ) {
            return $authRes;
        }

        return null;
    }
}
