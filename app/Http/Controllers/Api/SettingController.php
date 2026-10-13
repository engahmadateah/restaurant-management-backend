<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{

    private const RULES = [
        'restaurant_name' => ['required', 'string', 'max:100'],
        'phone' => ['nullable', 'string', 'max:30'],
        'address' => ['nullable', 'string', 'max:255'],
        'currency' => ['required', 'string', 'max:10'],
        'tax_rate' => ['required', 'numeric', 'between:0,1'],
        'delivery_fee' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        'loyalty_points_per_currency' => ['required', 'numeric', 'min:0', 'max:1000'],
        'loyalty_point_value' => ['required', 'numeric', 'min:0', 'max:1000000000'],
   
        'shift_max_hours' => ['required', 'numeric', 'between:1,24'],
      
        'qr_ordering_enabled' => ['required', 'boolean'],
        'online_ordering_enabled' => ['required', 'boolean'],
        'online_min_order' => ['required', 'numeric', 'min:0', 'max:1000000000'],
    ];


    private const BOOLEAN_KEYS = ['qr_ordering_enabled', 'online_ordering_enabled'];

    public function index()
    {
    
        return response()->json(Setting::allCached());
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        $unknown = array_diff(array_keys($request->input('settings')), array_keys(self::RULES));
        if (! empty($unknown)) {
            throw ValidationException::withMessages([
                'settings' => ['إعدادات غير معروفة: ' . implode('، ', $unknown)],
            ]);
        }

 
        $rules = [];
        foreach (array_keys($request->input('settings')) as $key) {
            $rules["settings.{$key}"] = self::RULES[$key];
        }

        $values = $request->validate($rules)['settings'];

        foreach ($values as $key => $value) {
   
            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            Setting::updateOrCreate(['key' => $key], ['value' => (string) ($value ?? '')]);
        }

        return response()->json(['message' => 'تم حفظ الإعدادات بنجاح']);
    }
}
