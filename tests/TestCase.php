<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    /**
     * Clean only testing Redis keys with testing prefix. Never FLUSHDB.
     */
    protected function cleanTestingRedis(): void
    {
        $prefix = (string) config('database.redis.options.prefix', '');
        $keys = Redis::keys('*');

        if (! empty($keys)) {
            foreach ($keys as $k) {
                if ($prefix !== '' && str_starts_with($k, $prefix)) {
                    $stripped = substr($k, strlen($prefix));
                    Redis::del($stripped);
                } else {
                    Redis::del($k);
                }
            }
        }
    }
}
