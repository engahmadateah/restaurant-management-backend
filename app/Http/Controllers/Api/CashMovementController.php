<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Services\CashDrawerCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * حركات نقدية يدوية على الصندوق المفتوح: مصروف مدفوع من الدرج، سحب، إيداع.
 *
 *  - بتأثر على "المتوقع" بالصندوق (شوف CashDrawerCalculator) فإغلاق الصندوق بيضل دقيق.
 *  - المصروف بينسجّل كمان كسجل بجدول expenses (تصنيفه من category، والافتراضي other)
 *    حتى يظهر بتقرير الأرباح من غير ما المدير يدخله مرتين.
 *  - ما بتنضاف حركة على صندوق مسكّر، ولا بيطلع من الصندوق أكتر من يلي فيه.
 *  - الحذف (تصحيح غلطة) لصاحب صلاحية drawer.manage_all بس، وبيتسجّل بسجل التدقيق.
 */
class CashMovementController extends Controller
{
    public function __construct(private CashDrawerCalculator $calculator)
    {
    }

    /** صندوقي أنا أو (صلاحية manage_all) أي صندوق، غير هيك 403 */
    private function authorizeDrawer(Request $request, CashDrawer $drawer): void
    {
        $canSeeAll = $request->user()->hasPermission('drawer.manage_all');

        abort_unless($canSeeAll || $drawer->user_id === $request->user()->id, 403, 'ما عندك صلاحية على هالصندوق');
    }

    /** الحركة لازم تكون تابعة للصندوق بالمسار، وإلا 404 */
    private function ensureBelongs(CashDrawer $drawer, CashMovement $movement): void
    {
        abort_unless($movement->cash_drawer_id === $drawer->id, 404);
    }

    public function index(Request $request, CashDrawer $drawer)
    {
        $this->authorizeDrawer($request, $drawer);

        $movements = $drawer->movements()
            ->with(['user:id,name', 'expense:id,category'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $movements,
            'totals' => $this->calculator->movementTotals($drawer),
        ]);
    }

    public function store(Request $request, CashDrawer $drawer)
    {
        $this->authorizeDrawer($request, $drawer);

        $validated = $request->validate([
            'type' => ['required', Rule::in(CashMovement::TYPES)],
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'reason' => 'required|string|max:255',
            // بس لنوع expense: تصنيف المصروف بجدول المصاريف (الافتراضي other)
            'category' => ['nullable', Rule::in(ExpenseController::CATEGORIES)],
        ]);

        $movement = DB::transaction(function () use ($request, $drawer, $validated) {
            // نفس قفل الإغلاق: ما بيصير إغلاق وإضافة حركة بنفس اللحظة
            $locked = CashDrawer::whereKey($drawer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'drawer' => ['هالصندوق مسكّر، ما بتنضاف عليه حركات'],
                ]);
            }

            $amountC = Money::cents($validated['amount']);
            $reason = trim($validated['reason']);
            $type = $validated['type'];

            // ما بيطلع من الصندوق أكتر من المتوقع حالياً
            if (in_array($type, CashMovement::OUTFLOW_TYPES, true)) {
                $expectedC = Money::cents($this->calculator->summarize($locked)['expected_cash']);

                if ($amountC > $expectedC) {
                    throw ValidationException::withMessages([
                        'amount' => ['المبلغ أكبر من الكاش المتوقع بالصندوق (' . number_format(Money::fromCents($expectedC), 2) . ')'],
                    ]);
                }
            }

            $expense = null;
            if ($type === CashMovement::TYPE_EXPENSE) {
                $expense = Expense::create([
                    'title' => $reason,
                    'category' => $validated['category'] ?? 'other',
                    'amount' => Money::fromCents($amountC),
                    'expense_date' => now()->toDateString(),
                    'notes' => 'مصروف نقدي من الصندوق #' . $locked->id,
                    'created_by' => $request->user()->id,
                ]);
            }

            return CashMovement::create([
                'cash_drawer_id' => $locked->id,
                'user_id' => $request->user()->id,
                'type' => $type,
                'amount' => Money::fromCents($amountC),
                'reason' => $reason,
                'expense_id' => $expense?->id,
            ]);
        });

        return response()->json($movement->load(['user:id,name', 'expense:id,category']), 201);
    }

    public function destroy(Request $request, CashDrawer $drawer, CashMovement $movement)
    {
        $this->authorizeDrawer($request, $drawer);
        $this->ensureBelongs($drawer, $movement);

        DB::transaction(function () use ($drawer, $movement) {
            $locked = CashDrawer::whereKey($drawer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'drawer' => ['هالصندوق مسكّر، ما بتتعدّل أرقامه'],
                ]);
            }

            $expense = $movement->expense;
            $movement->delete();
            // المصروف يلي انسجّل مع الحركة بيروح معها (وإلا بيضل مصروف وهمي بالتقارير)
            $expense?->delete();
        });

        return response()->json(['message' => 'تم حذف الحركة']);
    }
}
