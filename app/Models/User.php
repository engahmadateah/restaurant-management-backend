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

    
    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

 
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'created_by');
    }


    public function shiftSchedules()
    {
        return $this->hasMany(ShiftSchedule::class);
    }


    public function activeShift()
    {
        return $this->shifts()->whereNull('clock_out')->latest('clock_in')->first();
    }

   
    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

   
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ApiResetPasswordNotification($token));
    }
}