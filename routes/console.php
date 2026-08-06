<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Modules\Placement\Services\PlacementParticipationService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('placement:archive-sweeper', function (): void {
    $service = app(PlacementParticipationService::class);

    // Idempotent safety net (MODULE_PLACEMENT §13): Aktif containers that ever
    // had a participant but no longer have any Bekerja row. The sync path is
    // primary; this catches rows that slipped through. Idempotency lives in the
    // state guard (Arsip only from Aktif), not in a Redis lock.
    $containerIds = DB::table('placement_container as pc')
        ->where('pc.status', 'Aktif')
        ->whereExists(function ($query): void {
            $query->select(DB::raw('1'))
                ->from('placement_participants as pp')
                ->whereColumn('pp.placement_container_id', 'pc.id');
        })
        ->whereNotExists(function ($query): void {
            $query->select(DB::raw('1'))
                ->from('placement_participants as pp')
                ->whereColumn('pp.placement_container_id', 'pc.id')
                ->where('pp.status_penempatan', 'Bekerja');
        })
        ->pluck('pc.id');

    $archived = 0;
    foreach ($containerIds as $containerId) {
        if ($service->maybeArchiveContainer((int) $containerId)) {
            $archived++;
        }
    }

    $this->info("Placement archive sweep done: {$archived} container(s) archived.");
})->purpose('Idempotent daily safety net for automatic placement archive');

Schedule::command('placement:archive-sweeper')->daily();
