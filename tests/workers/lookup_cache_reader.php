<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

if ($argc < 5) {
    fwrite(STDERR, "usage: lookup_cache_reader.php table id expected_label signal_prefix\n");
    exit(2);
}

[$_, $table, $id, $expectedLabel, $signalPrefix] = $argv;

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "lookup cache reader requires APP_ENV=testing\n");
    exit(6);
}

require __DIR__.'/../../vendor/autoload.php';
/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "lookup cache reader booted outside testing\n");
    exit(6);
}

$cache = Cache::store('redis');
$lock = $cache->lock("lookup:{$table}:lock", 30);

if (! $lock->get()) {
    exit(3);
}

try {
    $rows = DB::table($table)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get(['code', 'label_id'])
        ->mapWithKeys(static fn (object $row): array => [$row->code => $row->label_id])
        ->all();

    $cache->put("lookup:{$table}:id", $rows, now()->addDay());
    $cache->put($signalPrefix.':written', true, 30);

    $status = 5;
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if ($cache->has($signalPrefix.':stop')) {
            $status = 4;
            break;
        }

        if (DB::table($table)->where('id', (int) $id)->value('label_id') === $expectedLabel) {
            $status = 0;
            break;
        }

        usleep(10000);
    }

    if ($status === 5) {
        fwrite(STDERR, 'timed out waiting for committed label'.PHP_EOL);
    }
} finally {
    $lock->release();
}

exit($status);
