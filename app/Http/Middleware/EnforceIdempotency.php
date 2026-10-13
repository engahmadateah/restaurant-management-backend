<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;


class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';


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
