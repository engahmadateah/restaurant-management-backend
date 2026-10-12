# التعديلات (ادمج الملفات فوق المشروع بنفس المسارات)

بعد الدمج:

    php artisan migrate        # migration جديدة لعمود delivery_status
    php artisan test
    # الـ scheduler لازم يكون شغال (cron: * * * * * php artisan schedule:run)

> ملاحظة: ما قدرت أشغّل PHP/الاختبارات بالبيئة عندي، فالتعديلات مراجعة بالقراءة فقط. شغّل `php artisan test` أول شي.

## 1) المودifiers والمخزون والكوبون
| الملف | التعديل |
|---|---|
| `app/Services/OrderService.php` | فرض `is_required` و`max_selections` و`is_available` للخيارات. الأخطاء بمفتاح `items`. تحميل المجموعات مرة وحدة مع الصنف (بدون query لكل سطر). |
| `InventoryController.php` | `stock_quantity`/`low_stock_threshold` بقيمة `null` ما عادت تكسر الـ UPDATE (خطأ 500 بسجل laravel.log)؛ null = بدون تغيير. |
| `CouponController.php` | الكوبون النسبي ≤ 100% بالإنشاء والتعديل (بيحسب النوع/القيمة الفعليين حتى لو تغيّر واحد منهم بس). + حماية بالـ OrderService للبيانات القديمة. |

## 2) التوصيل الملغي والحجوزات
- **Migration جديدة**: `delivery_status` صار string وبيقبل `cancelled`، وبتصلّح الطلبات الملغية القديمة.
- `Order::cancel()`: بيحط `delivery_status = cancelled` لطلبات التوصيل وبيبلّغ السائق.
- `OrderController::update`: ما بتسكّر طلب توصيل (`completed`) إلا بعد `delivered`.
- `DashboardController`: `deliveries_active` بس (pending/assigned/out_for_delivery) وبدون الملغي.
- `ReservationController` (معاد كتابته):
  - `PUT` ما عاد يتجاوز مسار الحالات؛ كل تغيير حالة بيمر بخريطة انتقالات واحدة (pending→confirmed/cancelled/no_show، confirmed→completed/cancelled/no_show).
  - endpoint جديد: `POST /reservations/{id}/no-show`.
  - تأكيد حجز بعد أيام ما بيقفل الطاولة. الطاولة بتصير `reserved` من ساعة قبل الموعد لساعتين بعده (`Reservation::shouldHoldTable`)، والأمر `reservations:sync-tables` (كل دقيقة) بيحوّلها.
  - الإلغاء/ما حضر بيفرّج الطاولة إلا إذا في حجز مؤكد تاني ماسكها هلأ.
  - منع الحجز المزدوج: transaction + قفل صف الطاولة.
  - فحص سعة الطاولة (`party_size`) وفحص إن وقت اليوم مش فات.
  - `table_id: null` بالتعديل صار يفك الطاولة. حذف حجز مؤكد بيفرّج الطاولة. حجز منتهي ما بينعدّل موعده.

## 3) تعريف الإيراد والربح (`app/Services/SalesReport.php`)
مصدر واحد للتقارير والداشبورد والتصدير:
- **الإيراد الصافي** = المبيعات (total) − الاسترجاعات المعتمدة − الضريبة. (المفتاح القديم `revenue` ضل موجود = `net_revenue`.)
- جديد بالـ sales: `gross_sales`, `tax`, `refunds`, `net_revenue`, `collected` (المقبوض الصافي), `outstanding` (غير المقبوض).
- **الربح** = الإيراد الصافي − المصاريف − كشوفات الرواتب (approved/paid، حسب `period_end` ضمن الفترة). بيرجع كمان `payroll` و`total_costs`.
- إيراد الأصناف/التصنيفات: بيشمل الخيارات الإضافية وبيوزّع خصم الطلب بالتناسب (بدون ضريبة ورسوم توصيل)، فمجموعها بيطابق المبيعات.
- `from`/`to` صارت بـ validation (تاريخ غلط أو `to < from` = 422 بدل 500).
- الداشبورد (`revenue_today/total/by_day`) بنفس التعريف + `gross_sales_today`.

## تنبيهات
- **تغيّر المعنى**: أرقام الإيراد أقل من قبل لأنها بدون ضريبة وبعد الاسترجاعات. نبّه مين بيستخدم الواجهة.
- الواجهة لازم تتعامل مع `delivery_status = cancelled` ومع حالة `no_show` بقائمة الحجوزات.
- إذا بتسجّل الرواتب كمان كمصروف بفئة `salaries` بجدول المصاريف، رح تنحسب مرتين بالربح؛ اختار طريقة وحدة.
- ما في تكلفة مواد (COGS) بالنظام، فالربح "تشغيلي" قبل تكلفة المواد.
- ضريبة الاسترجاع ما بتنعكس (بنطرح قيمة الاسترجاع كاملة من الإيراد).
- `APP_TIMEZONE=UTC`: تجميع الأيام لسا حسب UTC، وكمان أوقات الحجز بتتقارن بنفس التوقيت. غيّره لتوقيت المطعم.

## الاختبارات
- معدّل: `ReservationTest.php` (اختبارات التدفق صارت بحجز اليوم مع تثبيت الوقت).
- جديد: `ModifierRulesTest`, `CouponInventoryTest`, `DeliveryCancelTest`, `ReportDefinitionsTest`.
