<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Pdf\InvoicePdfBuilder;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    // بيرجع فاتورة الطلب كملف PDF جاهز للطباعة
    // GET /orders/{order}/invoice
    public function show(Order $order): Response
    {
        $pdf = InvoicePdfBuilder::build($order);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-' . $order->order_number . '.pdf"',
        ]);
    }
}
