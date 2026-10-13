<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PayrollPolicy
{
    private const MANAGE = 'payroll.manage';

  
    public function viewAny(User $user): bool
    {
        return true;
    }

  
    public function view(User $user, Payroll $payroll): Response|bool
    {
        if ($user->hasPermission(self::MANAGE) || $payroll->user_id === $user->id) {
            return true;
        }

        return Response::deny('ما عندك صلاحية توصل لهاد الكشف');
    }


    public function manage(User $user): bool
    {
        return $user->hasPermission(self::MANAGE);
    }

    public function update(User $user, Payroll $payroll): bool
    {
        return $user->hasPermission(self::MANAGE);
    }

    public function approve(User $user, Payroll $payroll): bool
    {
        return $user->hasPermission(self::MANAGE);
    }

    public function markPaid(User $user, Payroll $payroll): bool
    {
        return $user->hasPermission(self::MANAGE);
    }

    public function delete(User $user, Payroll $payroll): bool
    {
        return $user->hasPermission(self::MANAGE);
    }
}
