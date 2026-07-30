<?php

namespace Tests\Feature\Candidates;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shared\Files\FileStorageService;
use Tests\TestCase;

/**
 * Env-gated live R2 smoke for test VPS only.
 *
 * Enable with R2_LIVE_SMOKE=1 and real/synthetic R2 credentials in process env.
 * Never prints credentials or signed URLs.
 */
class R2LiveSmokeTest extends TestCase
{
    public function test_live_r2_private_put_presigned_url_and_no_checksum_501(): void
    {
        if (env('R2_LIVE_SMOKE') !== '1' && env('R2_LIVE_SMOKE') !== true) {
            $this->markTestSkipped('R2_LIVE_SMOKE not enabled');
        }

        config([
            'filesystems.disks.r2.driver' => 's3',
            'filesystems.disks.r2.key' => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'filesystems.disks.r2.secret' => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'filesystems.disks.r2.region' => env('R2_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'auto')),
            'filesystems.disks.r2.bucket' => env('R2_BUCKET', env('AWS_BUCKET')),
            'filesystems.disks.r2.endpoint' => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
            'filesystems.disks.r2.use_path_style_endpoint' => filter_var(
                env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', true)),
                FILTER_VALIDATE_BOOL,
            ),
            'filesystems.disks.r2.request_checksum_calculation' => 'when_required',
            'filesystems.disks.r2.response_checksum_validation' => 'when_required',
            'filesystems.disks.r2.retain_visibility' => false,
            'filesystems.disks.r2.throw' => true,
        ]);

        Storage::forgetDisk(FileStorageService::DISK);

        $files = app(FileStorageService::class);
        $key = 'smoke/candidates/'.Str::uuid()->toString().'.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        try {
            $files->storeCandidatePhoto($key, $png, 'image/png');
            $this->assertTrue($files->exists($key));

            $url = $files->temporaryUrl($key, FileStorageService::DEFAULT_SIGNED_TTL_SECONDS);
            $this->assertIsString($url);
            $this->assertNotSame('', $url);
            $short = $files->temporaryUrl($key, FileStorageService::MIN_SIGNED_TTL_SECONDS);
            $this->assertPresignedTtl($url, FileStorageService::DEFAULT_SIGNED_TTL_SECONDS);
            $this->assertPresignedTtl($short, FileStorageService::MIN_SIGNED_TTL_SECONDS);

            $unsigned = $this->unsignedUrl($url);
            $this->assertSame(parse_url($url, PHP_URL_PATH), parse_url($unsigned, PHP_URL_PATH));
            $this->assertNull(parse_url($unsigned, PHP_URL_QUERY));
            $this->assertContains(Http::timeout(10)->get($unsigned)->status(), [401, 403]);
            $signed = Http::timeout(10)->get($url);
            $this->assertTrue($signed->successful());
            $this->assertSame($png, $signed->body());
        } finally {
            try {
                $files->deleteObject($key);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    private function assertPresignedTtl(string $url, int $expected): void
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = $query['X-Amz-Expires'] ?? null;

        $this->assertIsNumeric($expires);
        $this->assertGreaterThanOrEqual(FileStorageService::MIN_SIGNED_TTL_SECONDS, (int) $expires);
        $this->assertLessThanOrEqual(FileStorageService::MAX_SIGNED_TTL_SECONDS, (int) $expires);
        $this->assertSame($expected, (int) $expires);
    }

    private function unsignedUrl(string $signedUrl): string
    {
        $queryAt = strpos($signedUrl, '?');

        return $queryAt === false ? $signedUrl : substr($signedUrl, 0, $queryAt);
    }
}
