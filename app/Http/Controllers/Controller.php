<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    public function generateShortcode() {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < 6; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    protected function encryptData(string $content, string $key) {
        // Generate a random initialization vector
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));        
        // Encrypt the plaintext with AES-256 using CBC mode
        $ciphertext = openssl_encrypt($content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        // Combine the IV and ciphertext for storage
        $encryptedData = $iv . $ciphertext;
        // Encode the encrypted data in base64 format for transmission/storage
        $encryptedData = base64_encode($encryptedData);
        return $encryptedData;
    }

    protected function decryptData(string $encryptedData, string $key) {
        // Decode the encrypted data from base64 format
        $encryptedData = base64_decode($encryptedData);
        // Extract the initialization vector from the encrypted data
        $iv = substr($encryptedData, 0, openssl_cipher_iv_length('aes-256-cbc'));
        // Extract the ciphertext from the encrypted data
        $ciphertext = substr($encryptedData, openssl_cipher_iv_length('aes-256-cbc'));
        // Decrypt the ciphertext using AES-256 with CBC mode
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext;
    }

    protected function rejectIfNotAuthenticated(?User $user = null): JsonResponse|null {
        if (!$user && !auth()->check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return null;
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
