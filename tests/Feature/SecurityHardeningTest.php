<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * W7-T4 — security hardening: headers, HTTPS redirect, APP_DEBUG off,
 * no committed secrets, Redis template (live noeviction/bind covered by
 * RedisEnvironmentTest), error page without leak.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_response_carries_security_headers_and_strict_csp(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('https://localhost/login');

        $response->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_http_redirects_to_https_in_production(): void
    {
        config(['app.env' => 'production']);

        $this->get('http://localhost/login')
            ->assertStatus(301)
            ->assertRedirect('https://localhost/login');
    }

    public function test_headers_exist_outside_production_too(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    public function test_environment_template_is_debug_off_error_log_without_secrets(): void
    {
        $env = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('error', $env['LOG_LEVEL']);
        $this->assertSame('127.0.0.1', $env['REDIS_HOST']);

        foreach (['APP_KEY', 'DB_PASSWORD', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY'] as $key) {
            $this->assertSame('', $env[$key], "{$key} must be empty in the template.");
        }
    }

    public function test_no_env_or_migrator_secret_file_is_committed(): void
    {
        $tracked = trim((string) shell_exec('git ls-files -- .env .env.migrator 2>/dev/null'));

        $this->assertSame('', $tracked, 'Secret env files must never be committed.');
    }

    public function test_debug_off_error_page_does_not_leak_message_or_paths(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/w7-boom', fn () => abort(500, 'W7_TOP_SECRET_MARKER'));

        $this->get('/_test/w7-boom')
            ->assertStatus(500)
            ->assertDontSee('W7_TOP_SECRET_MARKER')
            ->assertDontSee('app-modules')
            ->assertDontSee('vendor/laravel');
    }
}
