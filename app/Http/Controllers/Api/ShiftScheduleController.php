<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftScheduleController extends Controller
{
    
    public function index(Request $request)
    {
        $query = ShiftSchedule::with(['user:id,name,role', 'creator:id,name'])
            ->orderBy('scheduled_date')
            ->orderBy('start_time');

        $user = $request->user();
        if (! $user->hasPermission('schedules.manage')) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('scheduled_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('scheduled_date', '<=', $request->to);
        }

        return $query->paginate($this->perPage($request, 25));
    }


    public function store(Request $request)
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'scheduled_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'notes' => 'nullable|string',
        ]);

        $this->assertNoOverlap($validated);

        $validated['created_by'] = $request->user()->id;
        $validated['status'] = 'scheduled';

        $schedule = ShiftSchedule::create($validated);

        return response()->json($schedule->load(['user:id,name,role', 'creator:id,name']), 201);
    }

    public function show(Request $request, ShiftSchedule $shiftSchedule)
    {
        $user = $request->user();
        if (! $user->hasPermission('schedules.manage') && $shiftSchedule->user_id !== $user->id) {
            return response()->json(['message' => 'ما عندك صلاحية تشوف هاد الجدول'], 403);
        }

        return $shiftSchedule->load(['user:id,name,role', 'creator:id,name']);
    }

 
    public function update(Request $request, ShiftSchedule $shiftSchedule)
    {
        $user = $request->user();
        $isOwner = $shiftSchedule->user_id === $user->id;
        $canManage = $user->hasPermission('schedules.manage');

        if (! $canManage && ! $isOwner) {
            return response()->json(['message' => 'ما عندك صلاحية تعدل هاد الجدول'], 403);
        }

        if (! $canManage && $isOwner) {

            $validated = $request->validate([
                'status' => ['required', Rule::in(['confirmed', 'cancelled'])],
            ]);
            $shiftSchedule->update($validated);

            return $shiftSchedule->load(['user:id,name,role', 'creator:id,name']);
        }

        $validated = $request->validate([
            'user_id' => 'sometimes|required|exists:users,id',
            'scheduled_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i|after:start_time',
            'status' => ['sometimes', 'required', Rule::in(['scheduled', 'confirmed', 'cancelled', 'completed'])],
            'notes' => 'nullable|string',
        ]);

        $merged = array_merge([
            'user_id' => $shiftSchedule->user_id,
            'scheduled_date' => $shiftSchedule->scheduled_date->toDateString(),
            'start_time' => $shiftSchedule->start_time,
            'end_time' => $shiftSchedule->end_time,
        ], $validated);

        if (isset($validated['scheduled_date']) || isset($validated['start_time']) || isset($validated['end_time']) || isset($validated['user_id'])) {
            $this->assertNoOverlap($merged, $shiftSchedule->id);
        }

        $shiftSchedule->update($validated);

        return $shiftSchedule->load(['user:id,name,role', 'creator:id,name']);
    }

    public function destroy(Request $request, ShiftSchedule $shiftSchedule)
    {
        $this->authorizeManage($request);
        $shiftSchedule->delete();

        return response()->json(['message' => 'تم حذف الجدول']);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('schedules.manage'), 403, 'ما عندك صلاحية تدير جدولة الورديات');
    }

    protected function assertNoOverlap(array $data, ?int $ignoreId = null): void
    {
        $conflict = ShiftSchedule::where('user_id', $data['user_id'])
            ->where('scheduled_date', $data['scheduled_date'])
            ->where('status', '!=', 'cancelled')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($data) {
                $q->whereBetween('start_time', [$data['start_time'], $data['end_time']])
                    ->orWhereBetween('end_time', [$data['start_time'], $data['end_time']])
                    ->orWhere(function ($q2) use ($data) {
                        $q2->where('start_time', '<=', $data['start_time'])
                            ->where('end_time', '>=', $data['end_time']);
                    });
            })
            ->exists();

        if ($conflict) {
            abort(422, 'عندو تعارض وقت مع وردية مجدولة تانية لنفس الموظف بنفس اليوم');
        }
    }
}
