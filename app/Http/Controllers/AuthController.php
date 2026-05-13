<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationInvitation;
use App\Models\Configuration;
use App\Models\File;
use App\Models\InvitedUsers;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
use Mail;
use Symfony\Component\Uid\UuidV4;

class AuthController extends Controller
{
    /**
     * Handle a login request to the application.
     */
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (auth()->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended('dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Handle a logout request to the application.
     */
    public function logout(Request $request)
    {
        auth()->logout();
        return redirect('/login');
    }

    /**
     * Handle a register request to the application.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $invite = $request->input("invite"); // Invite token

        if ($invite) {
            /**
             * @var InvitedUsers|null
             */
            $invitedUser = InvitedUsers::validateToken($request->email, $invite);
            if (!$invitedUser) {
                return back()->withErrors([
                    'email' => 'Invalid or expired invite token.',
                ]);
            }

            $invitedUser->delete();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'api_token' => UuidV4::v4(),
                'role' => User::ROLE_USER,
            ]);
    
            auth()->login($user);
    
            return redirect()->intended('dashboard');
        }

        $role = User::ROLE_USER;
        
        $allow_signup = Configuration::getBool("allow_signup", false);
        if ($firstUser = User::first() && !$allow_signup) {
            return back()->withErrors([
                'email' => 'Registration is disabled.',
            ]);
        }
        else {
            if ($firstUser === null) {
                $role = User::ROLE_ADMIN;
            }
        }
        
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'api_token' => UuidV4::v4(),
            'role' => $role ?? User::ROLE_USER,
        ]);

        auth()->login($user);

        return redirect()->intended('dashboard');
    }

    /**
     * Reset user api token.
     */
    public function resetApiToken(Request $request)
    {
        $user = Auth::user();
        $user->api_token = UuidV4::v4();
        $user->save();

        return redirect()->back()->with('account_info', 'API key reset.');
    }

    public function updateImage(Request $request) {
        $request->validate([
            'short_code' => 'nullable|string|max:100',
            'url' => 'nullable|string|max:255',
        ]);

        /**
         * @var User
         */
        $user = Auth::user();

        $file = $this->resolveProfileImageFile(
            $request->input('short_code') ?: $request->input('url'),
            $user
        );

        if (!$file) {
            return back()->withErrors([
                'image' => 'Select an unprotected image from your uploads.',
            ]);
        }

        $user->image = $this->profileImagePath($file);
        $user->save();

        return back()->with('account_info', 'Profile image updated.');
    }

    public function update(Request $request, User $user) {
        /** @var User */
        $authUser = Auth::user();
        $isAdmin = $authUser->isAdmin();
        if ($user->id !== $authUser->id && !$isAdmin) {
            abort(403);
        }
        
        $userData = $request->only(
            'name',
            'email',
            'role',
            'storage_limit',
            'image',
            'accent_color',
        );

        // Unset empty values
        foreach ($userData as $key => $value) {
            if (($value === null || $value === "") && !in_array($key, ['image', 'accent_color'], true)) {
                unset($userData[$key]);
            }
        }

        if (array_key_exists('image', $userData) && $userData['image'] === "") {
            $userData['image'] = null;
        }

        if (array_key_exists('image', $userData) && $userData['image'] !== null) {
            $file = $this->resolveProfileImageFile($userData['image'], $user);

            if (!$file) {
                return back()->withErrors([
                    'image' => 'Select an unprotected image from this user\'s uploads.',
                ]);
            }

            $userData['image'] = $this->profileImagePath($file);
        }

        if (array_key_exists('accent_color', $userData)) {
            $accentColor = User::normalizeAccentColor($userData['accent_color']);

            if ($accentColor === null && trim((string) $userData['accent_color']) !== '') {
                return back()->withErrors([
                    'accent_color' => 'Choose a valid accent color.',
                ]);
            }

            $userData['accent_color'] = $accentColor;
        }

        if (!$isAdmin) {
            if (isset($userData['role']))
                unset($userData['role']);

            if (isset($userData['storage_limit']))
                unset($userData['storage_limit']);
        }

        if ($user->id === $authUser->id) {
            if (isset($userData['role']))
                unset($userData['role']);
        }

        try {
            $user->update($userData);
        } catch (\Throwable $th) {
            return back()->withErrors([
                'error' => "Failed to update user $user->name: " . $th->getMessage(),
            ]);
        }

        if ($request->query("_back")) { return back()->with('account_info', 'Profile updated.'); }

        return response()->json($user);
    }

    private function resolveProfileImageFile(?string $value, User $user): ?File
    {
        $shortCode = $this->profileImageShortCode($value);

        if (!$shortCode) {
            return null;
        }

        return File::where('short_code', $shortCode)
            ->where('user_id', $user->id)
            ->where('mime', 'LIKE', 'image/%')
            ->whereNull('password')
            ->where(function ($query) {
                $query->whereNull('expires')->orWhere('expires', '>', now());
            })
            ->first();
    }

    private function profileImageShortCode(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return $value;
        }

        $path = parse_url($value, PHP_URL_PATH);

        if (!$path || !preg_match('#^/f/([^/]+)(?:/|$)#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function profileImagePath(File $file): string
    {
        return "/f/$file->short_code";
    }

    public function invite(Request $request) {
        $user = Auth::user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $email = $request->input("email");

        $exist = InvitedUsers::where("email", $email)->first();

        if ($exist) {
            $exist->delete(); // Delete old invite
        }
        
        $token = InvitedUsers::generateToken($email);

        $url = route("register", ["invite" => $token]);
        
        Mail::to($email)->send(new RegistrationInvitation($url));

        if ($request->query("_back")) {
            return back()
                ->with("invite_info", "$email has been invited: $url");
        }

        
        return response()->json([
            'token' => $token,
        ]);
    }
}