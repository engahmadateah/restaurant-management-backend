<?php

namespace App\Services\Pdf;

use App\Models\Order;
use App\Models\Setting;

class InvoicePdfBuilder
{
    // بيبني بايتات PDF لفاتورة طلب جاهزة للطباعة
    public static function build(Order $order): string
    {
        $order->loadMissing(['items.menuItem', 'items.modifiers', 'table', 'customer', 'user', 'payments']);

        $restaurantName = Setting::get('restaurant_name') ?: 'Restaurant';
        $currency = Setting::get('currency') ?: 'USD';

        $pdf = new SimplePdfWriter;

        $pdf->text($restaurantName, 18, true, 'center');
        $pdf->text('Invoice / Receipt', 11, false, 'center');
        $pdf->spacer(6);
        $pdf->hr();

        $pdf->text('Order #: ' . $order->order_number, 11, true);
        $pdf->text('Date: ' . $order->created_at->format('Y-m-d H:i'), 10);
        $pdf->text('Type: ' . $order->order_type . '   Status: ' . $order->status, 10);

        if ($order->table) {
            $pdf->text('Table: ' . $order->table->table_number, 10);
        }
        if ($order->customer) {
            $pdf->text('Customer: ' . self::safe($order->customer->name), 10);
        }
        if ($order->user) {
            $pdf->text('Served by: ' . self::safe($order->user->name), 10);
        }

        $pdf->spacer(6);
        $pdf->hr();

        $headers = ['Item', 'Qty', 'Price', 'Line Total'];
        $widths = [270, 60, 90, 90];
        $rows = [];

        foreach ($order->items as $item) {
            $name = self::safe($item->menuItem?->name ?? ('#' . $item->menu_item_id));
            $rows[] = [$name, $item->quantity, number_format((float) $item->price, 2), number_format((float) $item->line_total, 2)];

            foreach ($item->modifiers as $modifier) {
                $rows[] = ['  + ' . self::safe($modifier->name ?? 'Add-on'), '', '+' . number_format((float) $modifier->extra_price, 2), ''];
            }
        }

        $pdf->table($headers, $rows, $widths);
        $pdf->spacer(10);
        $pdf->hr();

        $pdf->text('Subtotal: ' . number_format((float) $order->subtotal, 2) . ' ' . $currency, 10, false, 'right');
        if ((float) $order->discount > 0) {
            $pdf->text('Discount: -' . number_format((float) $order->discount, 2) . ' ' . $currency, 10, false, 'right');
        }
        $pdf->text('Tax: ' . number_format((float) $order->tax, 2) . ' ' . $currency, 10, false, 'right');
        $pdf->text('Total: ' . number_format((float) $order->total, 2) . ' ' . $currency, 13, true, 'right');

        if ($order->payments->isNotEmpty()) {
            $pdf->spacer(6);
            $pdf->text('Payments:', 10, true);
            foreach ($order->payments as $payment) {
                $pdf->text('- ' . $payment->method . ': ' . number_format((float) $payment->amount, 2) . ' ' . $currency . ' (' . $payment->status . ')', 9);
                if ($payment->method === 'cash' && $payment->tendered_amount !== null && (float) $payment->change_amount > 0) {
                    $pdf->text('    Received: ' . number_format((float) $payment->tendered_amount, 2) . ' ' . $currency
                        . ' / Change: ' . number_format((float) $payment->change_amount, 2) . ' ' . $currency, 9);
                }
            }
        }

        $pdf->spacer(14);
        $pdf->text('Thank you!', 10, false, 'center');

        return $pdf->output();
    }

    // بيرجع النص كما هو (المولّد PDF نفسه بيحوّل الحروف غير اللاتينية لأقرب مكافئ أو "?")
    protected static function safe(?string $text): string
    {
        return $text ?? '';
    }
}
