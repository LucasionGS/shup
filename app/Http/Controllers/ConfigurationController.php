<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->toArray();

        // Cast values properly
        foreach ($data as $key => $value) {
            if (is_numeric($value)) {
                $data[$key] = (int) $value;
            } elseif (is_bool($value)){
                $data[$key] = (bool) $value;
            } elseif ($value == "true" || $value == "false") {
                $data[$key] = $value == "true";
            }
        }

        // Remove meta fields
        unset($data['_method']);
        unset($data['_token']);

        foreach ($data as $key => $value) {
            Configuration::set($key, $value);
        }

        return back()->with('success', 'Configuration updated successfully');
    }
}
