<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\CashDrawer;
use App\Models\User;
use App\Services\CashDrawerCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class CashDrawerController extends Controller
{
    public function __construct(private CashDrawerCalculator $calculator)
    {
    }

    private function canSeeAll(Request $request): bool
    {
        return $request->user()->hasPermission('drawer.manage_all');
    }


    private function authorizeDrawer(Request $request, CashDrawer $drawer): void
    {
        abort_unless($this->canSeeAll($request) || $drawer->user_id === $request->user()->id, 403, 'ما عندك صلاحية على هالصندوق');
    }


    private function present(CashDrawer $drawer): CashDrawer
    {
        $summary = $this->calculator->summarize($drawer);

   
        if ($drawer->isOpen()) {
            $drawer->setAttribute('cash_sales', $summary['cash_sales']);
            $drawer->setAttribute('cash_refunds', $summary['cash_refunds']);
            $drawer->setAttribute('cash_expenses', $summary['cash_expenses']);
            $drawer->setAttribute('cash_withdrawals', $summary['cash_withdrawals']);
            $drawer->setAttribute('cash_deposits', $summary['cash_deposits']);
            $drawer->setAttribute('expected_cash', $summary['expected_cash']);
        }

        $drawer->setAttribute('by_method', $summary['by_method']);
        $drawer->setAttribute('is_stale', $drawer->isStale());

        return $drawer;
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:open,closed',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'user_id' => 'nullable|integer',
        ]);

        $query = CashDrawer::with(['user:id,name,role', 'closer:id,name']);

        if (! $this->canSeeAll($request)) {
            $query->where('user_id', $request->user()->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->status === 'open') {
            $query->whereNull('closed_at');
        } elseif ($request->status === 'closed') {
            $query->whereNotNull('closed_at');
        }

        if ($request->filled('from')) {
            $query->whereDate('opened_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('opened_at', '<=', $request->to);
        }

        $page = $query->latest('opened_at')->paginate($this->perPage($request));


        $page->getCollection()->transform(fn (CashDrawer $d) => $d->isOpen() ? $this->present($d) : $d);

        return $page;
    }

 
    public function current(Request $request)
    {
        $drawer = CashDrawer::with('user:id,name,role')
            ->where('user_id', $request->user()->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        return response()->json(['drawer' => $drawer ? $this->present($drawer) : null]);
    }

    public function show(Request $request, CashDrawer $drawer)
    {
        $this->authorizeDrawer($request, $drawer);

        return $this->present($drawer->load(['user:id,name,role', 'closer:id,name']));
    }

    public function open(Request $request)
    {
        $validated = $request->validate([
            'opening_float' => 'required|numeric|min:0|max:99999999',
        ]);

        $user = $request->user();

        $drawer = DB::transaction(function () use ($user, $validated) {
       
            User::whereKey($user->id)->lockForUpdate()->first();

            if (CashDrawer::where('user_id', $user->id)->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'drawer' => ['عندك صندوق مفتوح أصلاً، سكّره أول قبل ما تفتح واحد جديد'],
                ]);
            }

            return CashDrawer::create([
                'user_id' => $user->id,
                'shift_id' => $user->shifts()->whereNull('clock_out')->latest('clock_in')->value('id'),
                'opening_float' => $validated['opening_float'],
                'opened_at' => now(),
            ]);
        });

        return response()->json($this->present($drawer->load('user:id,name,role')), 201);
    }

    public function close(Request $request, CashDrawer $drawer)
    {
        $this->authorizeDrawer($request, $drawer);

        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:99999999',
            'notes' => 'nullable|string|max:1000',
        ]);

        $closed = DB::transaction(function () use ($request, $drawer, $validated) {
            $drawer = CashDrawer::whereKey($drawer->id)->lockForUpdate()->firstOrFail();

            if (! $drawer->isOpen()) {
                throw ValidationException::withMessages(['drawer' => ['هالصندوق مسكّر أصلاً']]);
            }

            $now = now();
            $summary = $this->calculator->summarize($drawer, $now);

            $countedC = Money::cents($validated['counted_cash']);
            $differenceC = $countedC - Money::cents($summary['expected_cash']);
            $notes = trim((string) ($validated['notes'] ?? ''));

      
            if ($differenceC !== 0 && $notes === '') {
                throw ValidationException::withMessages([
                    'notes' => ['في فرق بين المتوقع والمعدود، اكتب ملاحظة تشرحه'],
                ]);
            }

            $drawer->update([
                'closed_at' => $now,
                'closed_by' => $request->user()->id,
                'cash_sales' => $summary['cash_sales'],
                'cash_refunds' => $summary['cash_refunds'],
                'cash_expenses' => $summary['cash_expenses'],
                'cash_withdrawals' => $summary['cash_withdrawals'],
                'cash_deposits' => $summary['cash_deposits'],
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => Money::fromCents($countedC),
                'difference' => Money::fromCents($differenceC),
                'notes' => $notes === '' ? null : $notes,
            ]);

            return $drawer;
        });

        if ((float) $closed->difference !== 0.0) {
            $this->notifyManagers($closed);
        }

        return response()->json($this->present($closed->fresh()->load(['user:id,name,role', 'closer:id,name'])));
    }

    private function notifyManagers(CashDrawer $drawer): void
    {
        $name = $drawer->user?->name ?? ('#' . $drawer->user_id);
        $diff = (float) $drawer->difference;
        $kind = $diff < 0 ? 'عجز' : 'زيادة';
        $message = "صندوق {$name}: {$kind} " . number_format(abs($diff), 2)
            . ' (المتوقع ' . number_format((float) $drawer->expected_cash, 2)
            . '، المعدود ' . number_format((float) $drawer->counted_cash, 2) . ').';

        User::where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('drawer.manage_all'))
            ->each(fn (User $u) => AppNotification::notifyUser($u->id, 'drawer', "فرق بالصندوق ({$kind})", $message));
    }
}
