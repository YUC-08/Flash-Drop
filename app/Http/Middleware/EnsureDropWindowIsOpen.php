<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsureDropWindowIsOpen
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey || ! Str::isUuid($idempotencyKey)) {
            return response()->json([
                'message' => 'Geçersiz veya eksik Idempotency-Key başlığı.',
                'code' => 'invalid_idempotency_key',
            ], 422);
        }

        $dropParam = $request->route('drop');

        if (! is_string($dropParam)) {
            throw new LogicException('Route parameter [drop] must be an unbinded UUID string.');
        }

        if (! Str::isUuid($dropParam)) {
            return response()->json([
                'message' => 'Drop bulunamadı.',
                'code' => 'drop_not_found',
            ], 404);
        }

        $meta = Redis::hmget("drop:{$dropParam}:meta", ['starts_at', 'ends_at']);
        $startsAt = $meta['starts_at'] ?? $meta[0] ?? null;
        $endsAt = $meta['ends_at'] ?? $meta[1] ?? null;

        if ($startsAt === false || $startsAt === null || $endsAt === false || $endsAt === null) {
            return response()->json([
                'message' => 'Drop bulunamadı.',
                'code' => 'drop_not_found',
            ], 404);
        }

        $now = now()->getTimestamp();
        $startsAt = (int) $startsAt;
        $endsAt = (int) $endsAt;

        if ($now < $startsAt) {
            return response()->json([
                'message' => 'Drop henüz başlamadı.',
                'code' => 'drop_not_started',
            ], 403);
        }

        if ($now > $endsAt) {
            return response()->json([
                'message' => 'Drop süresi sona erdi.',
                'code' => 'drop_ended',
            ], 403);
        }

        return $next($request);
    }
}
