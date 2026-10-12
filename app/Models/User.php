<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ApiResetPasswordNotification;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // بيستثني كلمة السر من سجل audit_logs (مضافة أصلاً افتراضيًا بالـ trait،
    // موجودة هون بشكل صريح للتوضيح)
    protected array $auditExclude = ['password', 'remember_token'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'role',
        'phone',
        'is_active',
        'extra_permissions',
        'revoked_permissions',
        'salary_type',
        'hourly_rate',
        'monthly_salary',
        'weekly_overtime_threshold_hours',
        'overtime_multiplier',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'extra_permissions' => 'array',
            'revoked_permissions' => 'array',
            'hourly_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
        ];
    }

    /**
     * بيتحقق إذا المستخدم عنده صلاحية معيّنة (نظام صلاحيات دقيق فوق الـ role).
     * الأدمن دايمًا عنده كل الصلاحيات. غير هيك: بناخد صلاحيات الـ role
     * الافتراضية من config/permissions.php، منضيفلها extra_permissions
     * الخاصة فيه، ومنشيل منها revoked_permissions.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        $revoked = $this->revoked_permissions ?? [];
        if (in_array($permission, $revoked, true)) {
            return false;
        }

        $defaults = config("permissions.defaults.{$this->role}", []);
        if (in_array('*', $defaults, true)) {
            return true;
        }

        $granted = array_unique(array_merge($defaults, $this->extra_permissions ?? []));

        return in_array($permission, $granted, true);
    }

    // كل الصلاحيات الفعلية للمستخدم حالياً (مفيدة لعرضها بواجهة الإدارة)
    public function effectivePermissions(): array
    {
        if ($this->role === 'admin') {
            return config('permissions.all', []);
        }

        $defaults = config("permissions.defaults.{$this->role}", []);
        $granted = array_unique(array_merge($defaults, $this->extra_permissions ?? []));
        $revoked = $this->revoked_permissions ?? [];

        return array_values(array_diff($granted, $revoked));
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // الطلبات يلي هالمستخدم مسند إلها كسائق توصيل
    public function deliveries()
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    // ورديات الحضور والانصراف تبع هاد الموظف
    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    // المصاريف يلي هاد المستخدم سجّلها
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    // الورديات المجدولة مسبقًا لهاد الموظف (Shift Scheduling)
    public function shiftSchedules()
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    // الوردية المفتوحة حالياً (لسا ما سكّرها) إن وجدت
    public function activeShift()
    {
        return $this->shifts()->whereNull('clock_out')->latest('clock_in')->first();
    }

    // كشوفات الراتب تبع هاد الموظف
    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    // بنستبدل إشعار استعادة كلمة السر الافتراضي (يلي بيعتمد على route الويب
    // "password.reset" الغير موجود بنظام API فقط) بواحد مخصص آمن للـ API.
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ApiResetPasswordNotification($token));
    }
}