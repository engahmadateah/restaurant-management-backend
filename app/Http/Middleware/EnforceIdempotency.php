<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key على الطلبات يلي بتغيّر مال (إنشاء طلب، تسجيل دفعة).
 *
 * المشكلة: الكاشير بيضغط "دفع"، النت بينقطع قبل ما يوصل الرد، فبيضغط مرة تانية
 * فبينسجّل نفس الدفعة مرتين. مع هالهيدر الفرونت بيبعت نفس المفتاح بالمحاولة
 * التانية والسيرفر بيرجّع نفس الرد الأول بدل ما ينفّذ العملية من جديد.
 *
 * القواعد:
 *  - الهيدر اختياري: بدونه الطلب بيمر عادي (توافق مع أي عميل قديم).
 *  - المفتاح خاص بكل مستخدم.
 *  - نفس المفتاح + طلب مختلف (مسار أو محتوى) = 422، لأنه غالباً خطأ برمجي بالعميل.
 *  - نفس المفتاح والطلب الأول لسا شغّال = 409 (حاول بعد ثواني).
 *  - بنخزّن ردود النجاح (2xx) بس. الفشل (422/403/500...) بيحرّر المفتاح حتى
 *    المحاولة التانية بعد التصحيح تتنفّذ فعلاً.
 *  - الرد المعاد بيجي بهيدر Idempotent-Replayed: true
 */
class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** بعد هالمدة بنعتبر الطلب "العالق" ميّت (انقطعت العملية) ونسمح بإعادة التنفيذ */
    private const STALE_LOCK_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null || $key === '') {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return response()->json([
                'message' => 'Idempotency-Key غير صالح (8-100 حرف: أحرف وأرقام و - _ : .)',
            ], 422);
        }

        $userId = $request->user()?->id;
        if (! $userId) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        $hash = hash('sha256', $request->method() . '|' . $path . '|' . $request->getContent());

        // insertOrIgnore بدل try/catch على الـ unique: ما بيخرّب الـ transaction على Postgres
        $reserved = DB::table('idempotency_keys')->insertOrIgnore([
            'user_id' => $userId,
            'key' => $key,
            'method' => $request->method(),
            'path' => $path,
            'request_hash' => $hash,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $reserved) {
            $existing = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();

            // انحذف بين الـ insert والـ select (طلب فشل وحرّر المفتاح): جرّب من جديد
            if (! $existing) {
                return $this->handle($request, $next);
            }

            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'message' => 'نفس الـ Idempotency-Key انستخدم مع طلب مختلف. ولّد مفتاح جديد لكل عملية.',
                ], 422);
            }

            if ($existing->completed_at !== null) {
                return $this->replay($existing);
            }

            // الطلب الأول لسا شغّال: إلا إذا صار قديم كتير، بنستولي عليه بشكل ذرّي
            $stale = now()->subSeconds(self::STALE_LOCK_SECONDS);
            $takenOver = DB::table('idempotency_keys')
                ->where('id', $existing->id)
                ->whereNull('completed_at')
                ->where('locked_at', '<', $stale)
                ->update(['locked_at' => now(), 'updated_at' => now()]) === 1;

            if (! $takenOver) {
                return response()->json([
                    'message' => 'الطلب نفسه لسا عم ينعالج، استنى ثواني وجرّب.',
                ], 409)->header('Retry-After', '2');
            }
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->release($userId, $key);

            throw $e;
        }

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->update([
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'content_type' => $response->headers->get('Content-Type'),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $this->release($userId, $key);
        }

        return $response;
    }

    private function release(int $userId, string $key): void
    {
        DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->delete();
    }

    private function replay(object $stored): Response
    {
        return response($stored->response_body ?? '', (int) $stored->status_code)
            ->header('Content-Type', $stored->content_type ?: 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }
}
