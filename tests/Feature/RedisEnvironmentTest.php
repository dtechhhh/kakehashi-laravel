<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisEnvironmentTest extends TestCase
{
    public function test_local_redis_matches_the_baseline(): void
    {
        $localHosts = ['127.0.0.1', 'localhost', '::1'];
        $url = config('database.redis.default.url');

        $this->assertContains(config('database.redis.default.host'), $localHosts);
        $this->assertTrue(
            $url === null || in_array(parse_url((string) $url, PHP_URL_HOST), $localHosts, true)
        );
        $this->assertContains(Redis::connection()->ping(), [true, 'PONG', 1, '+PONG'], true);

        $info = Redis::connection()->command('info', ['server']);
        $version = is_array($info) ? (string) ($info['redis_version'] ?? '') : (string) $info;

        $this->assertMatchesRegularExpression('/^7\./', $version);
        $this->assertSame('yes', $this->redisConfig('protected-mode'));
        $this->assertSame('noeviction', $this->redisConfig('maxmemory-policy'));
        $this->assertGreaterThan(0, (int) $this->redisConfig('maxmemory'));
        $this->assertLessThanOrEqual(1073741824, (int) $this->redisConfig('maxmemory'));

        $bindHosts = preg_split('/\s+/', $this->redisConfig('bind'), flags: PREG_SPLIT_NO_EMPTY);
        $this->assertSame(
            [],
            array_diff(array_map(static fn (string $host): string => ltrim($host, '-'), $bindHosts), $localHosts)
        );
    }

    public function test_environment_template_uses_redis_without_secrets(): void
    {
        $env = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertIsArray($env);
        $this->assertSame('redis', $env['CACHE_STORE']);
        $this->assertSame('redis', $env['SESSION_DRIVER']);
        $this->assertSame('redis', $env['QUEUE_CONNECTION']);
        $this->assertSame('127.0.0.1', $env['REDIS_HOST']);
        $this->assertSame('', $env['APP_KEY']);
        $this->assertSame('', $env['DB_PASSWORD']);
        $this->assertSame('', $env['AWS_ACCESS_KEY_ID']);
        $this->assertSame('', $env['AWS_SECRET_ACCESS_KEY']);
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }

    private function redisConfig(string $key): string
    {
        $value = Redis::connection()->command('config', ['GET', $key]);

        return (string) ($value[$key] ?? $value[1] ?? $value[0] ?? '');
    }
}
