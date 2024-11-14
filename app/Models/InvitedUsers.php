<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitedUsers extends Model
{
    protected $table = 'invited_users';
    protected $fillable = ['email', 'token', 'expires_at'];
    protected $dates = ['expires_at'];

    public static function generateToken($email)
    {
        $token = bin2hex(random_bytes(32));
        $expires_at = now()->addDay();
        self::create([
            'email' => $email,
            'token' => $token,
            'expires_at' => $expires_at,
        ]);
        return $token;
    }

    public static function validateToken($email, $token)
    {
        $invitedUser = self::where('email', $email)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
        if ($invitedUser) {
            return $invitedUser;
        }
        return null;
    }
}
