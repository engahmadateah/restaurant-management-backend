<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;


trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('created', null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if (empty($changes)) {
                return;
            }
            $original = array_intersect_key($model->getOriginal(), $changes);
            $model->writeAuditLog('updated', $original, $changes);
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('deleted', $model->getAttributes(), null);
        });
    }

    protected function writeAuditLog(string $action, ?array $old, ?array $new): void
    {
        $exclude = array_merge(['password', 'remember_token'], $this->auditExclude ?? []);

        if ($old) {
            $old = array_diff_key($old, array_flip($exclude));
        }
        if ($new) {
            $new = array_diff_key($new, array_flip($exclude));
        }

        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => static::class,
                'auditable_id' => $this->getKey(),
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        } catch (\Throwable $e) {
          
            report($e);
        }
    }
}
