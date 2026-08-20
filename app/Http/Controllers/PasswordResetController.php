<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Password reset.
 *
 * The password_reset_tokens table and a "Forgot your password?" link have
 * existed since the beginning, but the route rendered a view that was never
 * written, so the link returned a 500 and there was no way to recover an
 * account without database access.
 */
class PasswordResetController extends Controller
{
    /** Reset links stop working after this long. */
    private const TOKEN_LIFETIME_MINUTES = 60;

    public function request()
    {
        return view('auth.password-request');
    }

    public function sendLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = $request->input('email');
        $user = User::firstWhere('email', $email);

        if ($user) {
            $token = bin2hex(random_bytes(32));

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                // Only the hash is stored: a leaked database should not hand
                // over working reset links.
                ['token' => hash('sha256', $token), 'created_at' => now()]
            );

            Mail::to($email)->send(new PasswordResetLink(
                route('password.reset', ['token' => $token, 'email' => $email])
            ));
        }

        // The same response either way, so this cannot be used to discover
        // which addresses have accounts.
        return back()->with('status', 'If that address has an account, a reset link is on its way.');
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.password-reset', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->input('email'))
            ->first();

        if (!$record || !hash_equals($record->token, hash('sha256', $request->input('token')))) {
            return back()->withErrors(['email' => 'This reset link is invalid.']);
        }

        // Compared against an explicit deadline: diffInMinutes() is signed in
        // Carbon 3, so a past timestamp yields a negative value and a naive
        // greater-than check would never treat a token as expired.
        $issuedAt = Carbon::parse($record->created_at);

        if ($issuedAt->addMinutes(self::TOKEN_LIFETIME_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->input('email'))->delete();

            return back()->withErrors(['email' => 'This reset link has expired.']);
        }

        $user = User::firstWhere('email', $request->input('email'));

        if (!$user) {
            return back()->withErrors(['email' => 'This reset link is invalid.']);
        }

        $user->password = $request->input('password');
        $user->save();

        // Single use, and any existing session elsewhere is invalidated.
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return redirect()->route('login')->with('status', 'Your password has been reset. You can sign in now.');
    }
}
