<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
 
    private const ROLES = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'driver'];


    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($this->wantsAll($request)) {
            return $query->latest()->limit(self::ALL_ROWS_CAP)->get();
        }

        return $query->latest()->paginate($this->perPage($request));
    }

 
    public function directory(Request $request)
    {
        $query = User::query()->select(['id', 'name', 'role', 'phone', 'is_active'])->orderBy('name');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return $query->limit(self::ALL_ROWS_CAP)->get();
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(self::ROLES)],
            'phone' => 'nullable|string|max:30',
            'is_active' => 'boolean',
        
            'salary_type' => 'sometimes|in:hourly,monthly',
            'hourly_rate' => 'nullable|numeric|min:0',
            'monthly_salary' => 'nullable|numeric|min:0',
            'weekly_overtime_threshold_hours' => 'nullable|integer|min:1',
            'overtime_multiplier' => 'nullable|numeric|min:1',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $validated['must_change_password'] = true;

        $user = User::create($validated);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return $user;
    }


    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(self::ROLES)],
            'phone' => 'nullable|string|max:30',
            'is_active' => 'boolean',

            'salary_type' => 'sometimes|in:hourly,monthly',
            'hourly_rate' => 'nullable|numeric|min:0',
            'monthly_salary' => 'nullable|numeric|min:0',
            'weekly_overtime_threshold_hours' => 'nullable|integer|min:1',
            'overtime_multiplier' => 'nullable|numeric|min:1',
        ]);


        $isSelf = $request->user()->id === $user->id;
        $deactivating = array_key_exists('is_active', $validated) && ! $validated['is_active'];
        $roleChanging = isset($validated['role']) && $validated['role'] !== $user->role;

        if ($isSelf && ($deactivating || $roleChanging)) {
            return response()->json(['message' => 'ما فيك تعطّل حسابك أو تغيّر دورك بنفسك'], 422);
        }

        $user->update($validated);


        if ($deactivating || $roleChanging) {
            $user->tokens()->delete();
        }

        return response()->json($user);
    }


    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ]);


        $isSelf = $request->user()->id === $user->id;

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => ! $isSelf,
        ]);


        $user->tokens()->delete();

        return response()->json(['message' => 'تم تغيير كلمة السر بنجاح']);
    }

 
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'ما فيك تحذف حسابك أنت'], 422);
        }

      
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->forceFill([
                'email' => Str::limit('deleted_' . $user->id . '_' . $user->email, 250, ''),
                'is_active' => false,
            ])->save();
            $user->delete();
        });

        return response()->json(['message' => 'تم حذف الموظف بنجاح']);
    }


    public function availablePermissions()
    {
        return response()->json([
            'all' => config('permissions.all', []),
            'defaults' => config('permissions.defaults', []),
        ]);
    }


    public function showPermissions(User $user)
    {
        return response()->json([
            'user_id' => $user->id,
            'role' => $user->role,
            'role_defaults' => config("permissions.defaults.{$user->role}", []),
            'extra_permissions' => $user->extra_permissions ?? [],
            'revoked_permissions' => $user->revoked_permissions ?? [],
            'effective_permissions' => $user->effectivePermissions(),
        ]);
    }


    public function updatePermissions(Request $request, User $user)
    {
        $validated = $request->validate([
            'extra_permissions' => 'sometimes|array',
            'extra_permissions.*' => ['string', Rule::in(config('permissions.all', []))],
            'revoked_permissions' => 'sometimes|array',
            'revoked_permissions.*' => ['string', Rule::in(config('permissions.all', []))],
        ]);

        $user->update($validated);

        return response()->json([
            'user_id' => $user->id,
            'extra_permissions' => $user->extra_permissions ?? [],
            'revoked_permissions' => $user->revoked_permissions ?? [],
            'effective_permissions' => $user->effectivePermissions(),
        ]);
    }
}
