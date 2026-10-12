<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['هذا الحساب غير مفعّل'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // تغيير كلمة السر للمستخدم المسجل دخوله حالياً (لازم يعرف كلمة السر القديمة)
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            // الجديدة لازم تختلف عن الحالية (خصوصاً المؤقتة يلي حطها الأدمن)
            'password' => 'required|string|min:6|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['كلمة السر الحالية غير صحيحة'],
            ]);
        }

        // must_change_password = false: هيك بيتحرّر الموظف المجبور على تغيير كلمة السر
        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        // منسحب كل الجلسات التانية (لو حدا سرق التوكن، تغيير كلمة السر لازم يطرده)،
        // ومنخلي التوكن الحالي حتى ما يتسجّل خروج المستخدم من الجهاز يلي غيّر منه
        $current = $user->currentAccessToken();
        $others = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $others->where('id', '!=', $current->id);
        }
        $others->delete();

        return response()->json([
            'message' => 'تم تغيير كلمة السر بنجاح',
            'user' => $user->fresh(),
        ]);
    }

    // إرسال رابط/توكن استعادة كلمة السر على إيميل المستخدم
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        // نفس الرد دايمًا سواء الإيميل موجود أو لا، حتى ما حدا يقدر يعرف مين مسجّل عنا
        return response()->json(['message' => 'إذا الإيميل مسجّل عنا، رح توصلك رسالة فيها رابط استعادة كلمة السر']);
    }

    // إعادة تعيين كلمة السر باستخدام التوكن يلي انبعت على الإيميل
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->update(['password' => Hash::make($password), 'must_change_password' => false]);
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'التوكن غير صالح أو منتهي الصلاحية'], 422);
        }

        return response()->json(['message' => 'تم تغيير كلمة السر بنجاح، فيك تسجل دخول من جديد']);
    }
}