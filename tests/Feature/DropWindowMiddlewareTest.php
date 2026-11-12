<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDropWindowIsOpen;
use App\Models\Drop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DropWindowMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTestingRedis();
    }

    protected function tearDown(): void
    {
        $this->cleanTestingRedis();
        parent::tearDown();
    }

    public function test_middleware_executes_zero_sql_queries(): void
    {
        $activeUuid = (string) Str::uuid();
        Redis::hmset("drop:{$activeUuid}:meta", [
            'starts_at' => now()->subHour()->getTimestamp(),
            'ends_at' => now()->addHour()->getTimestamp(),
        ]);

        $request = Request::create("/api/v1/drops/{$activeUuid}/reserve", 'POST');
        $request->headers->set('Idempotency-Key', (string) Str::uuid());

        $route = new Route('POST', '/api/v1/drops/{drop}/reserve', []);
        $route->bind($request);
        $route->setParameter('drop', $activeUuid);
        $request->setRouteResolver(fn () => $route);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $middleware = new EnsureDropWindowIsOpen;
        $response = $middleware->handle($request, function ($req) {
            return response()->json(['message' => 'ok'], 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, $queryCount, 'EnsureDropWindowIsOpen must not execute any database queries.');
    }

    public function test_middleware_receives_raw_string_uuid_before_substitute_bindings(): void
    {
        $activeUuid = (string) Str::uuid();
        Redis::hmset("drop:{$activeUuid}:meta", [
            'starts_at' => now()->subHour()->getTimestamp(),
            'ends_at' => now()->addHour()->getTimestamp(),
        ]);

        $receivedParam = null;
        $request = Request::create("/api/v1/drops/{$activeUuid}/reserve", 'POST');
        $request->headers->set('Idempotency-Key', (string) Str::uuid());

        $route = new Route('POST', '/api/v1/drops/{drop}/reserve', []);
        $route->bind($request);
        $route->setParameter('drop', $activeUuid);
        $request->setRouteResolver(fn () => $route);

        $middleware = new EnsureDropWindowIsOpen;
        $response = $middleware->handle($request, function ($req) use (&$receivedParam) {
            $receivedParam = $req->route('drop');

            return response()->json(['message' => 'ok']);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertIsString($receivedParam);
        $this->assertEquals($activeUuid, $receivedParam);
    }

    public function test_window_and_idempotency_validation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['drops:reserve']);

        $activeDrop = Drop::create([
            'title' => 'Active',
            'sku' => 'ACT-001',
            'price_cents' => 100,
            'total_stock' => 10,
            'available_stock' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        Redis::set("drop:{$activeDrop->uuid}:available_stock", 10);
        Redis::hmset("drop:{$activeDrop->uuid}:meta", [
            'starts_at' => $activeDrop->starts_at->getTimestamp(),
            'ends_at' => $activeDrop->ends_at->getTimestamp(),
        ]);

        $upcomingDrop = Drop::create([
            'title' => 'Upcoming',
            'sku' => 'UPC-001',
            'price_cents' => 100,
            'total_stock' => 10,
            'available_stock' => 10,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(4),
        ]);
        Redis::set("drop:{$upcomingDrop->uuid}:available_stock", 10);
        Redis::hmset("drop:{$upcomingDrop->uuid}:meta", [
            'starts_at' => $upcomingDrop->starts_at->getTimestamp(),
            'ends_at' => $upcomingDrop->ends_at->getTimestamp(),
        ]);

        $expiredDrop = Drop::create([
            'title' => 'Expired',
            'sku' => 'EXP-001',
            'price_cents' => 100,
            'total_stock' => 10,
            'available_stock' => 10,
            'starts_at' => now()->subDays(4),
            'ends_at' => now()->subDays(2),
        ]);
        Redis::set("drop:{$expiredDrop->uuid}:available_stock", 10);
        Redis::hmset("drop:{$expiredDrop->uuid}:meta", [
            'starts_at' => $expiredDrop->starts_at->getTimestamp(),
            'ends_at' => $expiredDrop->ends_at->getTimestamp(),
        ]);

        // 1. Idempotency-Key yok -> 422
        $this->postJson("/api/v1/drops/{$activeDrop->uuid}/reserve", ['quantity' => 1])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_idempotency_key']);

        // 2. Geçersiz Idempotency-Key -> 422
        $this->postJson("/api/v1/drops/{$activeDrop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => 'invalid-key'])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_idempotency_key']);

        // 3. Upcoming drop -> 403 drop_not_started
        $this->postJson("/api/v1/drops/{$upcomingDrop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403)
            ->assertJson(['code' => 'drop_not_started']);

        // 4. Expired drop -> 403 drop_ended
        $this->postJson("/api/v1/drops/{$expiredDrop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403)
            ->assertJson(['code' => 'drop_ended']);

        // 5. Rastgele UUID -> 404 drop_not_found
        $randomUuid = (string) Str::uuid();
        $this->postJson("/api/v1/drops/{$randomUuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(404)
            ->assertJson(['code' => 'drop_not_found']);

        // 6. Active drop -> 200/201 (başarılı rezervasyon)
        $this->postJson("/api/v1/drops/{$activeDrop->uuid}/reserve", ['quantity' => 1], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['uuid', 'drop_uuid', 'quantity', 'status', 'expires_at']]);
    }
}
