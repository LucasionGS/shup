<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationInvitation;
use App\Models\Configuration;
use App\Models\Directory;
use App\Models\File;
use App\Models\InvitedUsers;
use App\Models\PasteBin;
use App\Models\ShortURL;
use App\Models\UploadLink;
use App\Models\UploadSession;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'role' => User::ROLE_USER,
            ]);

            $user->issueApiToken();
            $user->save();

            auth()->login($user);

            return redirect()->intended('dashboard');
        }

        // `$firstUser = User::first() && !$allow_signup` assigned the boolean
        // result of the && to $firstUser, so it was never null and the
        // first-user-is-admin branch was unreachable, leaving a fresh instance
        // with no administrator.
        $firstUser = User::first();
        $allow_signup = Configuration::getBool("allow_signup", false);

        if ($firstUser !== null && !$allow_signup) {
            return back()->withErrors([
                'email' => 'Registration is disabled.',
            ]);
        }

        $role = $firstUser === null ? User::ROLE_ADMIN : User::ROLE_USER;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => $role,
        ]);

        $user->issueApiToken();
        $user->save();

        auth()->login($user);

        return redirect()->intended('dashboard');
    }

    /**
     * Reset user api token.
     */
    public function resetApiToken(Request $request)
    {
        $user = Auth::user();
        $user->issueApiToken();
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
        
        // name/email were previously written unvalidated, so a duplicate email
        // surfaced the raw driver exception to the user (see the catch below).
        $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

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
            if (($value === null || $value === "") && !in_array($key, ['image', 'accent_color', 'storage_limit'], true)) {
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

        if (array_key_exists('storage_limit', $userData)) {
            $storageLimit = $this->normalizeStorageLimit($userData['storage_limit']);

            if ($storageLimit === null) {
                return back()->withErrors([
                    'storage_limit' => 'Enter a quota like 10 GB, 500 MB, 1048576, or Unlimited.',
                ]);
            }

            $userData['storage_limit'] = $storageLimit;
        }

        try {
            $user->update($userData);
        } catch (\Throwable $th) {
            report($th);

            return back()->withErrors([
                'error' => "Failed to update user $user->name.",
            ]);
        }

        if ($request->query("_back")) { return back()->with('account_info', 'Profile updated.'); }

        return response()->json($user);
    }

    private function normalizeStorageLimit(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['0', 'unlimited', 'infinite', 'infinity', 'inf'], true)) {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(b|kb|mb|gb|tb)$/i', $value, $matches)) {
            return null;
        }

        $units = [
            'b' => 1,
            'kb' => 1024,
            'mb' => 1024 ** 2,
            'gb' => 1024 ** 3,
            'tb' => 1024 ** 4,
        ];

        $bytes = (float) $matches[1] * $units[strtolower($matches[2])];

        if ($bytes < 0 || $bytes > PHP_INT_MAX) {
            return null;
        }

        return (int) round($bytes);
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

    /**
     * Delete a user and everything they uploaded.
     *
     * The admin console has always rendered a delete button, but no route
     * existed behind it, so it returned 405. Content is expired individually
     * rather than mass-deleted so the blobs on disk go with the rows.
     */
    public function destroy(Request $request, User $user)
    {
        /** @var User */
        $authUser = Auth::user();

        if (!$authUser->isAdmin()) {
            abort(403);
        }

        if ($authUser->id === $user->id) {
            return back()->withErrors(['error' => 'You cannot delete your own account.']);
        }

        // Never leave an instance with no administrator.
        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            return back()->withErrors(['error' => 'This is the only administrator account.']);
        }

        foreach (File::where('user_id', $user->id)->cursor() as $file) {
            $file->expire();
        }

        foreach (Directory::where('user_id', $user->id)->cursor() as $directory) {
            $directory->expire();
        }

        PasteBin::where('user_id', $user->id)->delete();
        ShortURL::where('user_id', $user->id)->delete();
        UploadLink::where('user_id', $user->id)->delete();

        foreach (UploadSession::where('user_id', $user->id)->cursor() as $session) {
            $session->expire();
        }

        $name = $user->name;
        $user->delete();

        if ($request->query("_back")) {
            return back()->with('account_info', "$name and all of their content were deleted.");
        }

        return response()->json(['message' => 'User deleted'], 204);
    }
}