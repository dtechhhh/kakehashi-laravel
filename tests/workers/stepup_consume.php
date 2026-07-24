<?php

/**
 * Isolated worker for StepUpConcurrencyTest.
 * Boots a fresh app, uses Redis session + session.block, consumes one step-up token.
 *
 * argv: [sessionId, mutationKey, entityId, startAtMicrotime]
 * exit: 0 = mutated, 1 = STEPUP_REQUIRED, 2 = other
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;

if ($argc < 5) {
    fwrite(STDERR, "usage: stepup_consume.php sessionId mutationKey entityId startAt\n");
    exit(2);
}

[$_, $sessionId, $mutationKey, $entityId, $startAt] = $argv;

// Match parent concurrency test environment before bootstrap.
putenv('APP_ENV=testing');
putenv('SESSION_DRIVER=redis');
putenv('CACHE_STORE=redis');
putenv('SESSION_BLOCK=true');
putenv('SESSION_BLOCK_STORE=redis');
putenv('SESSION_BLOCK_LOCK_SECONDS=30');
putenv('SESSION_BLOCK_WAIT_SECONDS=10');
$_ENV['APP_ENV'] = 'testing';
$_ENV['SESSION_DRIVER'] = 'redis';
$_ENV['CACHE_STORE'] = 'redis';
$_ENV['SESSION_BLOCK'] = 'true';
$_ENV['SESSION_BLOCK_STORE'] = 'redis';
$_ENV['SESSION_BLOCK_LOCK_SECONDS'] = '30';
$_ENV['SESSION_BLOCK_WAIT_SECONDS'] = '10';

require __DIR__.'/../../vendor/autoload.php';
/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'session.driver' => 'redis',
    'session.block' => true,
    'session.block_store' => 'redis',
    'session.block_lock_seconds' => 30,
    'session.block_wait_seconds' => 10,
    'cache.default' => 'redis',
]);

// Temporary consume route (testing only).
Route::middleware(['web', 'auth'])
    ->post('/__test/stepup-consume', function (Request $request) {
        app(StepUpService::class)->require(
            StepUpAction::ANONYMIZE_PII,
            'candidate',
            (int) $request->input('entity_id'),
        );

        Cache::store('redis')->increment((string) $request->input('mutation_key'));

        return response()->json(['message' => 'MUTATED']);
    });

$startAt = (float) $startAt;
while (microtime(true) < $startAt) {
    usleep(1000);
}

$cookieName = (string) config('session.cookie');
$encryptedSession = encrypt(
    CookieValuePrefix::create($cookieName, $app['encrypter']->getKey()).$sessionId,
    false
);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::create(
    '/__test/stepup-consume',
    'POST',
    [
        'mutation_key' => $mutationKey,
        'entity_id' => (int) $entityId,
    ],
    [
        $cookieName => $encryptedSession,
    ],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]
);

try {
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $payload = json_decode($response->getContent(), true) ?? [];
    $kernel->terminate($request, $response);

    if ($status === 200 && ($payload['message'] ?? null) === 'MUTATED') {
        exit(0);
    }

    if ($status === 403 && ($payload['message'] ?? null) === 'STEPUP_REQUIRED') {
        exit(1);
    }

    fwrite(STDERR, "status={$status} body=".$response->getContent()."\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(3);
}
