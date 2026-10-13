<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftAutoCloser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{

    public function index(Request $request)
    {
        $query = Shift::with('user:id,name,role')->latest('clock_in');

        $user = $request->user();
        if (! $user->hasPermission('shifts.manage_all')) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('clock_in', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('clock_in', '<=', $request->to);
        }


        if ($request->boolean('auto_closed')) {
            $query->where('auto_closed', true);
        }

        return $query->get();
    }


    public function clockIn(Request $request, ShiftAutoCloser $closer)
    {
        $user = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $shift = DB::transaction(function () use ($user, $validated, $closer) {

            User::whereKey($user->id)->lockForUpdate()->first();

     
            $closer->closeStale($user->id);

            if ($user->shifts()->whereNull('clock_out')->exists()) {
                throw ValidationException::withMessages([
                    'shift' => ['عندك وردية مفتوحة أصلاً، لازم تسكرها أول'],
                ]);
            }

            return $user->shifts()->create([
                'clock_in' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json($shift, 201);
    }


    public function clockOut(Request $request, ShiftAutoCloser $closer)
    {
        $user = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $shift = DB::transaction(function () use ($user, $validated, $closer) {
            $shift = $user->shifts()->whereNull('clock_out')->latest('clock_in')->lockForUpdate()->first();

            if (! $shift) {
                throw ValidationException::withMessages([
                    'shift' => ['ما عندك وردية مفتوحة حالياً'],
                ]);
            }

     
     
            if ($shift->clock_in->lte(now()->subMinutes(Shift::maxMinutes()))) {
                $closer->close($shift);

                return $shift->fresh();
            }

            $shift->update([
                'clock_out' => now(),
                'notes' => $validated['notes'] ?? $shift->notes,
            ]);

            return $shift;
        });

        return response()->json($shift);
    }

    
    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'clock_in' => 'required|date',
            'clock_out' => 'required|date|after:clock_in|before_or_equal:now',
            'notes' => 'nullable|string',
        ]);

       
        $in = Carbon::parse($validated['clock_in'])->setTimezone(config('app.timezone'));
        $out = Carbon::parse($validated['clock_out'])->setTimezone(config('app.timezone'));

        if ($in->diffInMinutes($out) > 24 * 60) {
            throw ValidationException::withMessages([
                'clock_out' => ['مدة الوردية ما بتتعدى 24 ساعة'],
            ]);
        }

        DB::transaction(function () use ($shift, $validated, $in, $out, $request) {
            $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();

    
            foreach ([$shift->clock_in, $in] as $moment) {
                $locked = Payroll::where('user_id', $shift->user_id)
                    ->whereIn('status', ['approved', 'paid'])
                    ->whereDate('period_start', '<=', $moment->toDateString())
                    ->whereDate('period_end', '>=', $moment->toDateString())
                    ->exists();

                if ($locked) {
                    throw ValidationException::withMessages([
                        'clock_in' => ['هالوردية ضمن كشف راتب معتمد أو مدفوع، ما بينقدر تتعدّل'],
                    ]);
                }
            }

            $overlap = Shift::where('user_id', $shift->user_id)
                ->where('id', '!=', $shift->id)
                ->where('clock_in', '<', $out)
                ->where(fn ($q) => $q->whereNull('clock_out')->orWhere('clock_out', '>', $in))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'clock_in' => ['الأوقات بتتداخل مع وردية تانية لنفس الموظف'],
                ]);
            }

       
            $shift->update([
                'clock_in' => $in,
                'clock_out' => $out,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $shift->notes,
                'auto_closed' => false,
                'adjusted_by' => $request->user()->id,
            ]);
        });

        return response()->json($shift->fresh()->load('user:id,name,role'));
    }


    public function show(Request $request, Shift $shift)
    {
        $user = $request->user();

        if ((int) $shift->user_id !== (int) $user->id && ! $user->hasPermission('shifts.manage_all')) {
            return response()->json(['message' => 'ما عندك صلاحية توصل لهاد الجزء'], 403);
        }

        return $shift->load('user:id,name,role');
    }
}
