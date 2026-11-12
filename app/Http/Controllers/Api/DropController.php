<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Drop;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DropController extends Controller
{
    /**
     * Map of UUID to available stock.
     *
     * @var array<string, int>
     */
    protected array $stockMap = [];

    /**
     * Timestamp of the request.
     */
    protected int $currentTimestamp = 0;

    /**
     * List active and upcoming drops.
     *
     * Returns a cached listing of non-expired flash drops with real-time available stock from Redis.
     *
     * @response array{data: list<array{uuid: string, sku: string, title: string, price_cents: int, available_stock: int, status: string, starts_at: string, ends_at: string}>}
     */
    public function index(): JsonResponse
    {
        // 1. Statik alanlar 10 dakika önbellekte saklanır. available_stock cache'e dahil edilmez.
        $cachedDrops = Cache::remember('drops:listing', now()->addMinutes(10), function () {
            return Drop::query()
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get(['uuid', 'sku', 'title', 'price_cents', 'total_stock', 'starts_at', 'ends_at'])
                ->map(fn (Drop $drop) => [
                    'uuid' => $drop->uuid,
                    'sku' => $drop->sku,
                    'title' => $drop->title,
                    'price_cents' => (int) $drop->price_cents,
                    'total_stock' => (int) $drop->total_stock,
                    'starts_at' => $drop->starts_at->getTimestamp(),
                    'ends_at' => $drop->ends_at->getTimestamp(),
                ])
                ->all();
        });

        $now = now()->getTimestamp();
        $this->currentTimestamp = $now;

        // 2. Cache 10 dakika yaşadığı için süresi geçmiş olanları filtrele
        $filteredDrops = array_values(array_filter(
            $cachedDrops,
            fn (array $drop) => $drop['ends_at'] >= $now
        ));

        if (empty($filteredDrops)) {
            return response()->json(['data' => []], 200);
        }

        $uuids = array_column($filteredDrops, 'uuid');

        // 3. Stokları Redis pipeline ile tek round-trip'te çek
        $stocks = Redis::pipeline(function ($pipe) use ($uuids) {
            foreach ($uuids as $uuid) {
                $pipe->get("drop:{$uuid}:available_stock");
            }
        });

        $this->stockMap = [];
        $missingUuids = [];

        foreach ($uuids as $index => $uuid) {
            $stockValue = $stocks[$index] ?? false;
            if ($stockValue === false || $stockValue === null) {
                $missingUuids[] = $uuid;
            } else {
                $this->stockMap[$uuid] = (int) $stockValue;
            }
        }

        // Eksik anahtarlar varsa veritabanından tek sorgu ile kendini onar (self-heal)
        if (! empty($missingUuids)) {
            $recoveredDrops = Drop::query()
                ->whereIn('uuid', $missingUuids)
                ->get(['uuid', 'available_stock']);

            foreach ($recoveredDrops as $recovered) {
                Redis::setnx("drop:{$recovered->uuid}:available_stock", $recovered->available_stock);
                $this->stockMap[$recovered->uuid] = (int) $recovered->available_stock;
            }
        }

        // 4. Formatlama PHP 8.5 pipe operatörü (|>) ile yapılır
        $data = array_map(
            fn (array $drop) => $drop |> $this->mergeStock(...) |> $this->toResponse(...),
            $filteredDrops
        );

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Merge real-time stock into drop array.
     */
    protected function mergeStock(array $drop): array
    {
        $drop['available_stock'] = $this->stockMap[$drop['uuid']] ?? 0;

        return $drop;
    }

    /**
     * Transform drop array into API response structure.
     */
    protected function toResponse(array $drop): array
    {
        $startsAt = $drop['starts_at'];
        $endsAt = $drop['ends_at'];
        $now = $this->currentTimestamp;

        $status = ($startsAt <= $now && $now <= $endsAt) ? 'active' : 'upcoming';

        return [
            'uuid' => $drop['uuid'],
            'sku' => $drop['sku'],
            'title' => $drop['title'],
            'price_cents' => $drop['price_cents'],
            'available_stock' => $drop['available_stock'],
            'status' => $status,
            'starts_at' => Carbon::createFromTimestamp($startsAt)->toIso8601String(),
            'ends_at' => Carbon::createFromTimestamp($endsAt)->toIso8601String(),
        ];
    }
}
