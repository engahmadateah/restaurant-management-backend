# تحسينات المراجعة (الدفعة الأخيرة)

- **إصلاح فشل الاختبارات**: إضافة `config/view.php` (كان ناقص فكان Blade يرمي `Please provide a valid cache path`) + ملفات `.gitignore` داخل `storage/framework/*` و`storage/logs` و`bootstrap/cache` حتى المجلدات تنحفظ بـ git وما تنقص عند أي نسخة جديدة.
- **PHP 8.5**: `config/database.php` صار يستخدم `constant()` للثابت `Pdo\Mysql::ATTR_SSL_CA` (الإشارة المباشرة لـ `PDO::MYSQL_ATTR_SSL_CA` كانت تطلّع deprecated بكل اختبار حتى لو الفرع ما اتنفّذ).
- **السيدر**: كلمة السر `password` للحسابات التجريبية بس بـ `local`/`testing`. بأي بيئة تانية: إما `SEED_DEFAULT_PASSWORD` من `.env` أو كلمة عشوائية بتنطبع مرة وحدة.
- **`per_page`**: helper مشترك بـ `Controller` (`perPage()` بسقف 100 و`wantsAll()`)، مطبّق على Orders/Users/Expenses/AuditLog/ShiftSchedule/Payroll. `per_page=all` ما زال شغّال بس بسقف 1000 صف. القيم الصفر/السالبة بترجع للافتراضي.
- **`OrderService`**: منطق إنشاء الطلب انتقل من `OrderController` (398 → 158 سطر) لـ `app/Services/OrderService.php`، مقسّم لدوال صغيرة (مخزون، modifiers، كوبون، نقاط ولاء).
- **حسابات الطلب بالسنت**: المجموع والخصم والضريبة والتوصيل بتتحسب بأعداد صحيحة. ضريبة الطلب = مجموع ضرائب الأسطر بالضبط (قبل كان ممكن يصير فرق سنت، مثلاً 6.09 مقابل 6.10).
- **التقارير**: `revenue-by-category` (والتصدير) صار يستخدم subquery بدل `pluck('id')` (قائمة ids ضخمة كانت ممكن تتجاوز حد الـ placeholders بالـ DB)، و`limit` بـ top-items محصور بين 1 و100.
- **اختبارات جديدة** (53 → 72): الحجوزات، حاسبة الرواتب (overtime بالأسبوع)، تناسق الضريبة (بحالة بتفشل بالكود القديم)، سقف الصفحات، سلوك السيدر، التقارير.
- **CI**: `.github/workflows/tests.yml` بيشغّل الاختبارات على PHP 8.3 و8.4 مع كل push/PR.

## غير منجز (بحاجة قرار/وصول لـ packagist)
- **PDF بالعربي**: `SimplePdfWriter` بخط Helvetica ما بيدعم العربية؛ أي نص عربي بالفاتورة/التقارير بيتحول لـ `?`. الحل: `composer require mpdf/mpdf` (أو dompdf) وإعادة كتابة `InvoicePdfBuilder` و`ReportPdfBuilder`.

---

# شو تم إضافته/تعديله

## إضافات جديدة (هاي الدفعة)
- **تصدير التقارير PDF/CSV**: `GET /api/reports/export?type=sales|top-items|revenue-by-category|profit|staff-performance&format=csv|pdf&from=&to=` — محمي بصلاحية `reports.export`
- **طباعة فواتير**: `GET /api/orders/{order}/invoice` بيرجع PDF فاتورة جاهز للطباعة
- **جدولة الورديات المسبقة**: `apiResource /api/shift-schedules` (مختلف عن `/api/shifts` يلي هو تسجيل حضور فعلي clock-in/out) — فيه فحص تعارض وقت تلقائي
- **Pagination**: `orders`، `users`، `expenses` صارو مقسّمين لصفحات (`?per_page=20` افتراضي، `?per_page=all` للقائمة الكاملة)
- **صلاحيات دقيقة (Fine-grained Permissions)**: نظام صلاحيات فوق الـ role الأساسي — `config/permissions.php` + عمودي `extra_permissions`/`revoked_permissions` على `users` + middleware `permission:xxx`. إدارة عن طريق:
  - `GET /api/permissions` — كل الصلاحيات المتاحة
  - `GET /api/users/{user}/permissions` — صلاحيات موظف معيّن
  - `PUT /api/users/{user}/permissions` — منح/سحب صلاحيات (مثال: كاشير يشوف تقارير بس ما يصدرها)
- **Rate limiting على تسجيل الدخول**: `throttle:6,1` على `/login`، `/forgot-password`، `/reset-password` (حماية من brute force)
- **Audit Log**: كل عملية إنشاء/تعديل/حذف على الموديلات الحساسة (`User`, `Order`, `Expense`, `Payment`, `Refund`, `Setting`, `Coupon`, `RestaurantTable`, `MenuItem`) بتتسجل تلقائيًا (مين، إيمتى، شو تغيّر) — `GET /api/audit-logs` محمي بصلاحية `audit.view`

### ملاحظات تقنية مهمة
- ما كان في وصول لـ `packagist.org` من بيئة التطوير، فـ PDF وCSV اتعملوا بـ PHP صافي بدون أي composer package جديد (`app/Services/Pdf/SimplePdfWriter.php`, `app/Services/CsvExporter.php`). لما يصير في وصول إنترنت بالسيرفر، الأفضل نستبدلهم بـ `barryvdh/laravel-dompdf` و`PhpSpreadsheet` لدعم عربي كامل بالـ PDF وملفات `.xlsx` حقيقية.
- الـ CSV بيفتح مباشرة بإكسل/Google Sheets ومحافظ على النص العربي (فيه UTF-8 BOM).
- الـ PDF (فاتورة أو تقرير) بيدعم إنجليزي/أرقام/تواريخ بشكل ممتاز؛ النصوص العربية (زي أسماء الأصناف) رح تتحول تلقائيًا لأقرب حروف لاتينية أو "?" لأنو الخط المدمج (Helvetica) ما بيدعم عربي بدون مكتبة shaping متخصصة.
- **لازم تشغّل migrations جديدة**: `php artisan migrate` (audit_logs, shift_schedules, و عمودين جداد على users).

## الباك اند
- **PaymentController جديد** + route كامل (`/api/payments`) لتسجيل الدفعات وربطها بالطلبات
- **DashboardController جديد** (`/api/dashboard/stats`) بيرجع إحصائيات حقيقية: مبيعات اليوم، طلبات قيد الانتظار، الطاولات المشغولة، الأكثر مبيعًا... بدل ما الفرونت يحسبها يدوي
- عمود جديد `payment_status` على جدول `orders` (unpaid / partial / paid) يتحدث تلقائيًا مع كل دفعة
- نسبة الضريبة صارت تُقرأ من جدول `settings` (`tax_rate`) بدل ما تكون مكتوبة بالكود
- **Seeder كامل** ببيانات ديمو واقعية: مطعم "زيتون"، 5 تصنيفات، 17 صنف، 10 طاولات، 3 زبائن، 2 كوبون، مستخدمين (أدمن + كاشير)

## طريقة التشغيل
```bash
composer install
cp .env.example .env   # إذا ما كان موجود
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

### حسابات الدخول بعد الـ seed
| الدور | الإيميل | كلمة المرور |
|---|---|---|
| أدمن | admin@restaurant.com | password |
| كاشير | cashier@restaurant.com | password |

## الفرونت اند
- صفحات جديدة: **الزبائن، الكوبونات، الإعدادات** (الإعدادات مقصورة على الأدمن فقط)
- **Dashboard** محدّثة تستخدم `/dashboard/stats` وفيها قسم "الأكثر مبيعًا"
- **Orders** صارت تدعم: اختيار زبون مسجّل، تطبيق كود كوبون عند إنشاء الطلب، عرض حالة الدفع، تسجيل دفعة (نقدًا/بطاقة/إلكتروني)، وطباعة إيصال

```bash
npm install
npm run dev
```

## اللي لسا ناقص (خطوات جاية)
- تحسين شامل للتصميم (UI/UX) بحيث يصير جاهز فعليًا للعرض/البيع
- صلاحيات أدق حسب الدور (cashier ما لازم يشوف/يعدّل إعدادات مثلاً — الحماية حاليًا بالفرونت بس، لازم تنضاف بالباك اند كمان)
- رفع صور فعلي للأصناف (حاليًا الحقل موجود بس ما في upload UI)

## إضافات جديدة (محاكاة أنظمة المطاعم الكبرى) - الباك اند فقط، الفرونت لسا ما اتحدّث

### 1. نقاط الولاء (Loyalty Points)
- عمود `loyalty_points` على `customers` + جدول `loyalty_transactions` (سجل كل حركة كسب/استبدال/تعديل)
- الزبون بياخد نقاط تلقائيًا لما الطلب يوصل "مكتمل" (نقطة لكل دينار - قابل للتعديل من `settings.loyalty_points_per_currency`)
- ممكن يستبدل نقاطه كخصم إضافي وهو بينشئ طلب جديد (`redeem_points`) - قيمة النقطة من `settings.loyalty_point_value`
- `GET /api/customers/{id}/loyalty` لعرض الرصيد والسجل، و `POST /api/customers/{id}/loyalty/adjust` لتعديل يدوي من الأدمن

### 2. نظام المطبخ (Kitchen Display System)
- الطلب بيتنقل: `pending -> preparing -> ready -> completed` مع توقيت فعلي لكل مرحلة (`preparing_at`, `ready_at`, `completed_at`)
- `GET /api/kitchen/queue` طابور المطبخ (الأقدم أول)
- `POST /api/kitchen/orders/{id}/start` بدء التحضير
- `POST /api/kitchen/orders/{id}/ready` الطلب جاهز
- `POST /api/kitchen/orders/{id}/serve` تسليم الطلب (صالة/تيك أواي فقط)

### 3. إدارة المخزون (Inventory)
- أعمدة `track_stock`, `stock_quantity`, `low_stock_threshold` على `menu_items`
- خصم تلقائي من المخزون مع كل طلب + رفض الطلب إذا الكمية غير متوفرة + إشعار أدمن لما المخزون ينخفض
- `GET /api/inventory` و `GET /api/inventory/low-stock` و `POST /api/inventory/{id}/restock` و `PUT /api/inventory/{id}/settings`

### 4. أنواع الطلبات والتوصيل (Delivery + Driver Assignment)
- `order_type` (dine_in/delivery/takeaway) كان موجود أصلاً بس صار مفعّل بالكامل لطلبات التوصيل
- أعمدة جديدة على `orders`: `driver_id`, `delivery_address`, `delivery_phone`, `delivery_fee`, `delivery_status`
- دور مستخدم جديد `driver` (زي admin/cashier)
- `GET /api/deliveries` الطلبات النشطة، `POST /api/orders/{id}/assign-driver`، `POST /api/orders/{id}/delivery-status`
- لما التوصيل يوصل لحالة `delivered` الطلب يتقفل تلقائيًا (نفس منطق `markCompleted()`)

### التشغيل بعد هاد التحديث
```bash
php artisan migrate --seed
```
هاد رح يشغّل الميغريشنز الجديدة، ويضيف مستخدم سائق ديمو (`driver@restaurant.com` / `password`)، ويفعّل تتبع مخزون على أصناف "المشاوي"، ويعطي الزبون "محمد العلي" 150 نقطة ولاء جاهزة للتجربة.

## إضافات جديدة #2 (احترافية أكتر) - الباك اند فقط

### 5. خيارات إضافية للأصناف (Modifiers / Add-ons)
مثال واقعي: صنف "بيتزا" ممكن يكون إله مجموعة "الحجم" (وسط/كبير، اختيار وحيد وإجباري)
ومجموعة "إضافات" (جبنة، زيتون... اختياري وممكن أكتر من وحدة).
- جدول `modifier_groups` (تابع لصنف معيّن): `is_required`, `max_selections`
- جدول `modifiers` (تابع لمجموعة): `extra_price`
- جدول `order_item_modifier`: بيسجّل الخيارات يلي فعلًا اختارها الزبون بكل طلب مع
  اسم وسعر الخيار وقت الطلب (snapshot) حتى لو تغيّر السعر لاحقًا بالمنيو
- عند إنشاء الطلب (`POST /api/orders`)، كل عنصر بالمصفوفة `items` ممكن ياخد
  `modifiers: [id, id, ...]` وبينحسب السعر تلقائيًا (سعر الصنف + مجموع أسعار
  الخيارات) × الكمية، مع تحقق إنو كل خيار فعلًا تابع لنفس الصنف
- Routes:
  - `GET/POST /api/menu-items/{menuItem}/modifier-groups`
  - `PUT/DELETE /api/menu-items/{menuItem}/modifier-groups/{modifierGroup}`
  - `POST/PUT/DELETE /api/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers/{modifier}`
- تمت إضافة مجموعتين تجريبيتين (الحجم + إضافات) على صنف "شيش طاووق" بالـ seeder

### 6. نظام استرجاع الأموال (Refunds)
Workflow بسيط ومهني للتعامل مع طلبات الاسترجاع بدل ما يصير حذف/تعديل يدوي
على الدفعات مباشرة:
- جدول `refunds`: `order_id`, `payment_id` (اختياري), `amount`, `reason`,
  `status` (pending/approved/rejected), `requested_by`, `processed_by`, `admin_note`
- أي مستخدم مسجل دخول يقدر يطلب استرجاع (`POST /api/refunds`) - بيتحقق إنو
  المبلغ المطلوب ما يتجاوز المتاح فعليًا (المدفوع - المسترجع سابقًا)
- الموافقة/الرفض النهائي محصور بالأدمن فقط (`role:admin`):
  - `POST /api/refunds/{refund}/approve` - بيعلّم الدفعة المرتبطة `refunded`
    إذا المبلغ يغطيها بالكامل، وبيحدّث `payment_status` على الطلب تلقائيًا
    (partial/refunded حسب المبلغ المسترجع مقارنة بالمدفوع)
  - `POST /api/refunds/{refund}/reject`
- إشعارات تلقائية: الأدمن بياخد إشعار لما يوصل طلب استرجاع جديد، ويلي طلب
  الاسترجاع بياخد إشعار بنتيجة الموافقة/الرفض

### التشغيل بعد هاد التحديث
```bash
php artisan migrate --seed
```
مجموعة Postman جاهزة فيها كل الـ endpoints الجديدة مع body مثال لكل واحد:
`postman/collections/new-features-modifiers-refunds.postman_collection.json`
(استوردها بـ Postman، وعدّل متغيّر `token` بعد ما تعمل login).


## 2026-10-05 - Hardening: permissions, payments, refunds, stock, coupons

- **routes/api.php**: permission middleware now actually applied. Reads need `orders.view`; creating/updating orders, payments, customers needs `orders.manage`; menu/categories/inventory/modifier writes need `menu.manage`; tables writes need `tables.manage`. New permissions: `orders.delete`, `payments.manage`, `customers.manage` (delete customer, adjust loyalty), `coupons.manage` (manager/admin by default, see config/permissions.php).
- **PaymentController**: rewritten. Transaction + row lock, no direct `refunded` status, cannot overpay, cannot pay cancelled orders, confirmed payments are immutable (amount/status), only pending payments can be deleted, `payment_status` recomputed from a single source (`Order::syncPaymentStatus`).
- **RefundController**: transaction + lock on store/approve, pending refunds reserve the amount, payment must belong to the order, per-payment cap, re-validation on approve, fixed double counting when a payment is marked `refunded`.
- **OrderController**: stock check + decrement inside the transaction with `lockForUpdate` (lines of the same item are aggregated), unavailable items rejected, invalid/expired coupon = 422 (was silently ignored), coupon discount capped at subtotal, loyalty points only spent for the discount actually applied, status state machine (`Order::STATUS_TRANSITIONS`), `Order::cancel()` restores stock + points + table and refuses paid orders, delete refused when payments/refunds exist, `per_page` capped at 100.
- **Coupons**: `expires_at` is valid through the end of that day.
- **Migration** `2026_10_05_100000`: `orders.payment_status` enum -> string (needed on MySQL/Postgres where `refunded` was not a legal value).
- **Tests**: 35 feature tests (permissions, payments/refunds, order flow). `phpunit.xml` now uses sqlite `:memory:` so `RefreshDatabase` can never wipe the dev database.

## 2026-10-05 (2) - Kitchen & delivery permissions
- New permissions `kitchen.manage` (queue/start/ready/serve) and `deliveries.manage` (list, delivery-status, assign-driver together with `orders.manage`). Defaults: chef/waiter/cashier/manager get kitchen; driver/cashier/manager get deliveries. Grant/revoke per user via `PUT /users/{user}/permissions` as before.
- DeliveryController: a driver only lists/updates orders assigned to him; assigning requires an active driver and an order that is not completed/cancelled/out for delivery; a cancelled/completed order can no longer be flipped to completed by `delivered`; `out_for_delivery` requires kitchen status `ready`.
- Tests: `tests/Feature/KitchenDeliveryTest.php` (8 tests, 43 total).

## 2026-10-05 (3) - Tokens & production hardening
- **Tokens**: `sanctum.expiration` is now 7 days by default (`SANCTUM_EXPIRATION`, minutes). Existing tokens older than that expire immediately, so staff log in once more. `sanctum:prune-expired` is scheduled daily (needs `schedule:run` in cron).
- **EnsureUserIsActive** middleware (`active`) on the whole authenticated group: a deactivated user is rejected even with a valid token.
- **UserController**: deactivating, changing role or deleting a user revokes all his tokens; an admin cannot deactivate or demote himself.
- **AuthController**: `changePassword` revokes the other sessions and keeps the current one; `forgot-password` always returns the same 200 message (no email enumeration).
- **CORS**: new `config/cors.php`, origins from `CORS_ALLOWED_ORIGINS` (default: localhost:3000 and :5173). Before, any origin was allowed.
- **Errors**: `/api/*` always answers JSON; unauthenticated calls without `Accept: application/json` return 401 instead of 500 (`route('login')` does not exist).
- **`.env.production.example`**: production template (`APP_DEBUG=false`, `LOG_LEVEL=warning`, MySQL, CORS, token lifetime).
- **Tests**: `tests/Feature/TokenAndHardeningTest.php` (10 tests, 53 total).
