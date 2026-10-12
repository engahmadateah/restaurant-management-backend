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
    // الأدوار المسموحة بالنظام
    private const ROLES = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'driver'];

    // قائمة الموظفين، مع إمكانية فلترة حسب الدور أو الحالة أو البحث بالاسم/الإيميل
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

    /**
     * دليل موظفين مختصر (بدون إيميل ولا رواتب) لقوائم الاختيار: تعيين سائق، توليد رواتب،
     * جدولة ورديات، فلتر الحضور. قبل كانت هالشاشات تستدعي GET /users (role:admin) فكانت
     * بتفشل بـ 403 عند المدير والكاشير رغم إنو عندهم صلاحية الشاشة.
     */
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

    // إنشاء حساب موظف جديد
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(self::ROLES)],
            'phone' => 'nullable|string|max:30',
            'is_active' => 'boolean',
            // إعدادات الراتب (اختيارية وقت الإنشاء، ممكن تتحدد لاحقًا من التعديل)
            'salary_type' => 'sometimes|in:hourly,monthly',
            'hourly_rate' => 'nullable|numeric|min:0',
            'monthly_salary' => 'nullable|numeric|min:0',
            'weekly_overtime_threshold_hours' => 'nullable|integer|min:1',
            'overtime_multiplier' => 'nullable|numeric|min:1',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        // كلمة السر يلي حطها الأدمن مؤقتة: الموظف لازم يغيّرها بأول دخول
        $validated['must_change_password'] = true;

        $user = User::create($validated);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return $user;
    }

    // تعديل بيانات موظف (بدون كلمة السر - إلها endpoint خاص فيها)
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(self::ROLES)],
            'phone' => 'nullable|string|max:30',
            'is_active' => 'boolean',
            // إعدادات الراتب - تتقرأ من PayrollCalculator وقت توليد كشف راتب جديد
            'salary_type' => 'sometimes|in:hourly,monthly',
            'hourly_rate' => 'nullable|numeric|min:0',
            'monthly_salary' => 'nullable|numeric|min:0',
            'weekly_overtime_threshold_hours' => 'nullable|integer|min:1',
            'overtime_multiplier' => 'nullable|numeric|min:1',
        ]);

        // ما بتعطّل حسابك ولا بتغيّر دورك بإيدك: هيك بتقفل الأدمن الأخير برا النظام
        $isSelf = $request->user()->id === $user->id;
        $deactivating = array_key_exists('is_active', $validated) && ! $validated['is_active'];
        $roleChanging = isset($validated['role']) && $validated['role'] !== $user->role;

        if ($isSelf && ($deactivating || $roleChanging)) {
            return response()->json(['message' => 'ما فيك تعطّل حسابك أو تغيّر دورك بنفسك'], 422);
        }

        $user->update($validated);

        // تعطيل الحساب أو تغيير الدور = سحب كل توكناته حتى يرجع يسجل دخول بالصلاحيات الجديدة
        if ($deactivating || $roleChanging) {
            $user->tokens()->delete();
        }

        return response()->json($user);
    }

    // إعادة تعيين كلمة سر موظف من طرف الأدمن
    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ]);

        // لو الأدمن صفّر كلمة سر موظف تاني: كلمة السر الجديدة مؤقتة وبيجبره يغيّرها.
        // لو صفّر كلمة سر حسابه هو: هو اختارها بنفسه فما منجبره
        $isSelf = $request->user()->id === $user->id;

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => ! $isSelf,
        ]);

        // منسحب كل التوكنات القديمة تبعه حتى يضطر يسجل دخول من جديد
        $user->tokens()->delete();

        return response()->json(['message' => 'تم تغيير كلمة السر بنجاح']);
    }

    // حذف حساب موظف (ما بينحذفش حسابك الحالي لحالك)
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'ما فيك تحذف حسابك أنت'], 422);
        }

        // soft delete: طلباته ودفعاته وورديات وكشوف رواتبه بتضل بالسجل (قبل كان حذفه يمسحهم بالـ cascade).
        // منحرّر الإيميل حتى ينفع يتسجّل موظف جديد بنفس العنوان، ومنعطّل الحساب.
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

    // كل الصلاحيات المتاحة بالنظام + الصلاحيات الافتراضية لكل دور (للواجهة)
    public function availablePermissions()
    {
        return response()->json([
            'all' => config('permissions.all', []),
            'defaults' => config('permissions.defaults', []),
        ]);
    }

    // صلاحيات موظف معيّن: الافتراضية من دوره + الإضافية + المسحوبة + الفعلية النهائية
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

    // بيمنح/يسحب صلاحيات دقيقة لموظف فوق صلاحيات دوره الافتراضية
    // مثال: كاشير تشوف تقارير بس ما تعدلها:
    //   { "extra_permissions": ["reports.view"], "revoked_permissions": [] }
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
