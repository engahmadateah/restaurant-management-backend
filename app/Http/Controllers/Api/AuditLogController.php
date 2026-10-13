<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
   
    private const ACTIONS = ['created', 'updated', 'deleted'];

 
    public function index(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'action' => 'nullable|in:' . implode(',', self::ACTIONS),
            'model' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z_\\\\]+$/'],
            'auditable_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = AuditLog::with('user:id,name,role')->latest()->latest('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('model')) {
            $model = $request->model;
            $fullyQualified = str_contains($model, '\\') ? $model : 'App\\Models\\' . $model;
            $query->where('auditable_type', $fullyQualified);
        }

        if ($request->filled('auditable_id') && $request->filled('model')) {
            $query->where('auditable_id', $request->auditable_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return $query->paginate($this->perPage($request, 25));
    }

  
    public function filters()
    {
        $models = AuditLog::query()
            ->select('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->map(fn (string $type) => class_basename($type))
            ->unique()
            ->sort()
            ->values();

        $userIds = AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id');

        $users = User::withTrashed()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json([
            'actions' => self::ACTIONS,
            'models' => $models,
            'users' => $users,
        ]);
    }

    public function show(AuditLog $auditLog)
    {
        return $auditLog->load('user:id,name,role');
    }
}
