<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public const CATEGORIES = ['rent', 'utilities', 'salaries', 'supplies', 'maintenance', 'marketing', 'other'];

    // قائمة المصاريف، مع فلترة اختيارية حسب التصنيف أو مجال تاريخ
    public function index(Request $request)
    {
        $query = Expense::with('creator:id,name')->latest('expense_date');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }

        // بنحسب الإجمالي على كل النتائج المفلترة (مش بس صفحة واحدة) قبل التقسيم
        $total = (float) (clone $query)->sum('amount');

        if ($this->wantsAll($request)) {
            return response()->json(['data' => $query->limit(self::ALL_ROWS_CAP)->get(), 'total' => $total]);
        }

        $paginated = $query->paginate($this->perPage($request));

        return response()->json(array_merge($paginated->toArray(), ['total' => $total]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()->id;

        $expense = Expense::create($validated);

        return response()->json($expense->load('creator:id,name'), 201);
    }

    public function show(Expense $expense)
    {
        return $expense->load('creator:id,name');
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => ['sometimes', 'required', Rule::in(self::CATEGORIES)],
            'amount' => 'sometimes|required|numeric|min:0',
            'expense_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
        ]);

        $expense->update($validated);

        return response()->json($expense->load('creator:id,name'));
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return response()->json(['message' => 'تم حذف المصروف بنجاح']);
    }
}
