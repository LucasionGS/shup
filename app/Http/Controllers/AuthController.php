<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\User;
use Auth;
use Illuminate\Http\Request;
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

        $allow_signup = Configuration::getBool("allow_signup", false);
        if (!$allow_signup && User::first()) {
            return redirect()->back()->withErrors([
                'email' => 'Registration is disabled.',
            ]);
        }
        
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'api_token' => UuidV4::v4(),
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
}