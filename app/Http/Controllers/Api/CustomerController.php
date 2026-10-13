<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
  
    public function index(Request $request)
    {
        $request->validate(['search' => 'nullable|string|max:100']);

        $query = Customer::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%' . addcslashes(trim($request->search), '%_\\') . '%';
     
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->latest()->latest('id');

        if ($request->has('page') || $request->has('per_page')) {
            return $this->wantsAll($request)
                ? $query->limit(self::ALL_ROWS_CAP)->get()
                : $query->paginate($this->perPage($request, 20));
        }

        return $query->limit(self::ALL_ROWS_CAP)->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:customers,phone',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
        ]);

        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        return $customer->load('orders');
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|unique:customers,phone,' . $customer->id,
            'email' => 'nullable|email',
            'address' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }
}