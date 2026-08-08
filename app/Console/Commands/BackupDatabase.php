<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * W7-T6 — daily PostgreSQL dump to the separate R2 backup bucket
 * (BACKUP_AND_RECOVERY §2.1/§5). Redis is never backed up as business data.
 */
final class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--database= : database name (default DB_DATABASE)}
        {--bucket= : R2 backup bucket (default R2_BACKUP_BUCKET)}
        {--keep-local=3 : days to keep local dumps}';

    protected $description = 'pg_dump + gzip + upload to the separate R2 backup bucket';

    public function handle(): int
    {
        $database = (string) ($this->option('database') ?: config('database.connections.pgsql.database'));
        $bucket = (string) ($this->option('bucket') ?: env('R2_BACKUP_BUCKET'));

        if ($bucket === '') {
            $this->error('R2_BACKUP_BUCKET is not set.');

            return self::FAILURE;
        }

        $dir = '/var/backups/kakehashi';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }

        $file = $dir.'/kakehashi_db_'.now('Asia/Tokyo')->format('Ymd_His').'.sql.gz';
        $connection = config('database.connections.pgsql');
        $command = sprintf(
            'PGPASSWORD=%s pg_dump -h 127.0.0.1 -p %s -U %s --no-owner --no-acl -d %s | gzip > %s',
            escapeshellarg((string) $connection['password']),
            escapeshellarg((string) $connection['port']),
            escapeshellarg((string) $connection['username']),
            escapeshellarg($database),
            escapeshellarg($file),
        );

        $process = Process::fromShellCommandline($command, timeout: 600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($file) || filesize($file) === 0) {
            $this->error('pg_dump/gzip failed.');

            return self::FAILURE;
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.r2.region', 'auto'),
            'endpoint' => (string) config('filesystems.disks.r2.endpoint'),
            'use_path_style_endpoint' => (bool) config('filesystems.disks.r2.use_path_style_endpoint', true),
            'credentials' => [
                'key' => (string) config('filesystems.disks.r2.key'),
                'secret' => (string) config('filesystems.disks.r2.secret'),
            ],
        ]);

        $key = basename($file);
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => fopen($file, 'rb'),
        ]);

        $size = (int) $client->headObject(['Bucket' => $bucket, 'Key' => $key])['ContentLength'];
        if ($size === 0) {
            $this->error('Uploaded artifact has zero size.');

            return self::FAILURE;
        }

        $keepDays = max(1, (int) $this->option('keep-local'));
        Process::fromShellCommandline(
            sprintf("find %s -name '*.sql.gz' -mtime +%d -delete", escapeshellarg($dir), $keepDays),
        )->run();

        file_put_contents(
            $dir.'/backup.log',
            date(DATE_ATOM)." backup ok {$key} size={$size}\n",
            FILE_APPEND,
        );

        $this->info("backup ok {$key} size={$size} bucket={$bucket}");

        return self::SUCCESS;
    }
}
