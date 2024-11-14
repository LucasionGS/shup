<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationInvitation;
use App\Models\Configuration;
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

        if (auth()->attempt($credentials)) {
            // Authentication passed...
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

        return redirect()->back();
    }

    public function updateImage(Request $request) {
        // TODO: Make safer for the love of all that's holy.
        $imageUrl = $request->input("url");

        /**
         * @var User
         */
        $user = Auth::user();

        $user->image = $imageUrl;
        $user->save();

        return back();
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
        );

        // Unset empty values
        foreach ($userData as $key => $value) {
            if ($value === null || $value === "") {
                unset($userData[$key]);
            }
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

        if ($request->query("_back")) { return back(); }

        return response()->json($user);
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