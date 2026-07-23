<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisEnvironmentTest extends TestCase
{
    public function test_redis_is_reachable_local_only_and_version_7(): void
    {
        $host = (string) config('database.redis.default.host');
        $url = config('database.redis.default.url');
        $localHosts = ['127.0.0.1', 'localhost', '::1'];

        $this->assertContains(
            $host,
            $localHosts,
            'Development Redis host must be local only.'
        );
        $this->assertTrue(
            $url === null || in_array(parse_url((string) $url, PHP_URL_HOST), $localHosts, true),
            'Development Redis URL must be unset or local only.'
        );

        $pong = Redis::connection()->ping();

        $this->assertTrue(
            $pong === true || $pong === 'PONG' || $pong === 1 || $pong === '+PONG',
            'Redis PING must succeed for local development.'
        );

        $info = Redis::connection()->command('info', ['server']);
        $version = is_array($info)
            ? (string) ($info['redis_version'] ?? '')
            : (string) $info;
        if (! is_array($info) || $version === '') {
            $raw = (string) Redis::connection()->command('info', ['server']);
            if (preg_match('/^redis_version:(.+)$/m', $raw, $m) === 1) {
                $version = trim($m[1]);
            }
        }

        $this->assertMatchesRegularExpression(
            '/^7\.\d+/',
            $version,
            'Authority requires Redis 7.x (local pin 7.4.x).'
        );

        $bind = Redis::connection()->command('config', ['GET', 'bind']);
        $protectedMode = Redis::connection()->command('config', ['GET', 'protected-mode']);
        $maxmemory = Redis::connection()->command('config', ['GET', 'maxmemory']);
        $policy = Redis::connection()->command('config', ['GET', 'maxmemory-policy']);

        $bindValue = $this->redisConfigValue($bind, 'bind');
        $protectedValue = $this->redisConfigValue($protectedMode, 'protected-mode');
        $maxmemoryValue = $this->redisConfigValue($maxmemory, 'maxmemory');
        $policyValue = $this->redisConfigValue($policy, 'maxmemory-policy');

        $this->assertNotSame('', $bindValue);
        // Redis 7 default may use optional-bind form "-::1" (local IPv6; dash = non-fatal if missing).
        $bindHosts = array_map(
            static fn (string $h): string => ltrim($h, '-'),
            array_values(array_filter(preg_split('/\s+/', trim($bindValue)) ?: []))
        );
        $this->assertSame(
            [],
            array_diff($bindHosts, $localHosts),
            'Redis server must bind only to local addresses.'
        );
        $this->assertSame('yes', $protectedValue);
        $this->assertSame('1073741824', (string) $maxmemoryValue, 'maxmemory must be 1gb (1073741824 bytes).');
        $this->assertSame('noeviction', $policyValue);
    }

    public function test_env_example_wires_redis_for_cache_session_queue(): void
    {
        $examplePath = base_path('.env.example');
        $this->assertFileExists($examplePath);

        $example = file_get_contents($examplePath);
        $this->assertIsString($example);

        $this->assertMatchesRegularExpression('/^CACHE_STORE=redis\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=redis\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=redis\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^SESSION_LIFETIME=30\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^REDIS_HOST=127\.0\.0\.1\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^REDIS_CLIENT=phpredis\s*$/m', $example);

        // Negative: template must not point Redis at a public/non-local host.
        $this->assertDoesNotMatchRegularExpression(
            '/^REDIS_HOST=(?!127\.0\.0\.1$|localhost$|::1$)[^\s]+/m',
            $example,
            '.env.example must not set REDIS_HOST to a non-local address.'
        );
        $this->assertDoesNotMatchRegularExpression('/^REDIS_URL=/m', $example);
    }

    public function test_secrets_gate_env_not_tracked_and_template_has_no_secrets(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertIsString($gitignore);
        $this->assertMatchesRegularExpression('/^\.env\s*$/m', $gitignore);

        $tracked = [];
        exec('git -C '.escapeshellarg(base_path()).' ls-files --error-unmatch .env 2>/dev/null', $tracked, $code);
        $this->assertNotSame(0, $code, '.env must not be tracked by Git.');
        $this->assertSame([], $tracked);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertIsString($example);

        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=null\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^MAIL_USERNAME=null\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=null\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_ACCESS_KEY_ID=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_SECRET_ACCESS_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_DEFAULT_REGION=auto\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_ENDPOINT=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AWS_USE_PATH_STYLE_ENDPOINT=true\s*$/m', $example);
        $this->assertDoesNotMatchRegularExpression(
            '/^APP_KEY=base64:.+/m',
            $example,
            '.env.example must not embed a real APP_KEY.'
        );
    }

    public function test_redis_queue_dispatches_after_database_commit(): void
    {
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }

    /**
     * @param  array<string, mixed>|list<mixed>|string|null  $response
     */
    private function redisConfigValue(mixed $response, string $key): string
    {
        if (is_array($response)) {
            if (array_key_exists($key, $response)) {
                return (string) $response[$key];
            }

            // phpredis sometimes returns [key, value]
            if (isset($response[0], $response[1]) && (string) $response[0] === $key) {
                return (string) $response[1];
            }

            if (isset($response[1])) {
                return (string) $response[1];
            }
        }

        return is_scalar($response) ? (string) $response : '';
    }
}
