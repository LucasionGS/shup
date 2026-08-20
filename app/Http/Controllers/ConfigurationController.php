<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    /**
     * Settings an administrator is allowed to write.
     *
     * Every posted field used to become a configuration key, so a single
     * request could define arbitrary settings (and any future non-config field
     * added to the form would silently become one).
     */
    private const ALLOWED_KEYS = [
        'app_title',
        'allow_signup',
        'allow_anonymous_upload',
        'max_upload_bytes',
    ];

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'app_title' => 'nullable|string|max:80',
            'allow_signup' => 'nullable|in:true,false,0,1',
            'allow_anonymous_upload' => 'nullable|in:true,false,0,1',
            'max_upload_bytes' => 'nullable|integer|min:0',
        ]);

        $data = $request->only(self::ALLOWED_KEYS);

        if (array_key_exists('app_title', $data)) {
            $data['app_title'] = trim((string) $data['app_title']);
        }

        // Cast values properly
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = (bool) $value;
            } elseif ($value === "true" || $value === "false") {
                $data[$key] = $value === "true";
            } elseif (is_numeric($value)) {
                $data[$key] = (int) $value;
            }
        }

        foreach ($data as $key => $value) {
            Configuration::set($key, $value);
        }

        return back()->with('success', 'Configuration updated successfully');
    }
}
