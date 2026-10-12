<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $query = Review::with(['menuItem', 'customer']);

        if ($request->has('menu_item_id')) {
            $query->where('menu_item_id', $request->menu_item_id);
        }

        if ($request->has('is_approved')) {
            $query->where('is_approved', $request->boolean('is_approved'));
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        return $query->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->whereNull('deleted_at')],
            'customer_id' => 'nullable|exists:customers,id',
            'order_id' => 'nullable|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        // اللي عنده صلاحية الموافقة (أدمن/مدير) تقييمه بينشر مباشرة، غيره بده موافقة إدارية
        $validated['is_approved'] = $request->user()->hasPermission('reviews.approve');

        $review = Review::create($validated);

        return response()->json($review->load(['menuItem', 'customer']), 201);
    }

    public function show(Review $review)
    {
        return $review->load(['menuItem', 'customer', 'order']);
    }

    public function update(Request $request, Review $review)
    {
        $validated = $request->validate([
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'is_approved' => 'sometimes|required|boolean',
        ]);

        $review->update($validated);

        return response()->json($review->load(['menuItem', 'customer']));
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return response()->json(['message' => 'تم حذف التقييم بنجاح']);
    }

    /**
     * موافقة إدارية على تقييم معلّق (يظهر للعموم بعدها)
     */
    public function approve(Review $review)
    {
        $review->update(['is_approved' => true]);

        return response()->json($review->load(['menuItem', 'customer']));
    }

    /**
     * ملخص تقييمات صنف معيّن: المعدل وعدد التقييمات
     */
    public function ratingSummary(MenuItem $menuItem)
    {
        $approved = $menuItem->reviews()->where('is_approved', true);

        return response()->json([
            'menu_item_id' => $menuItem->id,
            'average_rating' => round((float) $approved->avg('rating'), 2),
            'total_reviews' => $approved->count(),
            'breakdown' => [
                5 => (clone $approved)->where('rating', 5)->count(),
                4 => (clone $approved)->where('rating', 4)->count(),
                3 => (clone $approved)->where('rating', 3)->count(),
                2 => (clone $approved)->where('rating', 2)->count(),
                1 => (clone $approved)->where('rating', 1)->count(),
            ],
        ]);
    }
}
