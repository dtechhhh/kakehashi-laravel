<?php

namespace Shared\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * W3-T7 — candidate face photos on private R2 only (API_CONTRACTS §6.4).
 * Documents are Google Drive URLs; never pass document bytes here.
 */
final class FileStorageService
{
    public const DISK = 'r2';

    public const DEFAULT_SIGNED_TTL_SECONDS = 900;

    public const MIN_SIGNED_TTL_SECONDS = 300;

    public const MAX_SIGNED_TTL_SECONDS = 900;

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Store raw photo bytes under a private object key.
     *
     * @return string object_key written
     */
    public function storeCandidatePhoto(string $objectKey, string $contents, string $mimeType): string
    {
        $objectKey = ltrim($objectKey, '/');
        if ($objectKey === '' || str_contains($objectKey, '..')) {
            throw new InvalidArgumentException('PHOTO_OBJECT_KEY_INVALID');
        }

        $ok = $this->disk()->put($objectKey, $contents, [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        if ($ok === false) {
            throw new RuntimeException('PHOTO_STORE_FAILED');
        }

        return $objectKey;
    }

    /**
     * Short-lived signed URL (PRD: 5–15 minutes; default 15).
     */
    public function temporaryUrl(string $objectKey, int $ttlSeconds = self::DEFAULT_SIGNED_TTL_SECONDS): string
    {
        $objectKey = ltrim($objectKey, '/');
        $ttlSeconds = max(self::MIN_SIGNED_TTL_SECONDS, min(self::MAX_SIGNED_TTL_SECONDS, $ttlSeconds));

        $adapter = $this->disk();

        if (! method_exists($adapter, 'temporaryUrl') && ! method_exists($adapter, 'providesTemporaryUrls')) {
            // Local/testing: deterministic pseudo-signed URL (not R2).
            return $this->localTemporaryUrl($objectKey, $ttlSeconds);
        }

        try {
            return $adapter->temporaryUrl($objectKey, now()->addSeconds($ttlSeconds));
        } catch (RuntimeException $e) {
            // Local driver without temporary URL callback.
            if (str_contains($e->getMessage(), 'temporary URL') || str_contains($e->getMessage(), 'temporaryUrl')) {
                return $this->localTemporaryUrl($objectKey, $ttlSeconds);
            }

            throw $e;
        }
    }

    public function deleteObject(string $objectKey): void
    {
        $objectKey = ltrim($objectKey, '/');
        if ($objectKey === '') {
            return;
        }

        $this->disk()->delete($objectKey);
    }

    public function exists(string $objectKey): bool
    {
        return $this->disk()->exists(ltrim($objectKey, '/'));
    }

    private function localTemporaryUrl(string $objectKey, int $ttlSeconds): string
    {
        $expires = now()->addSeconds($ttlSeconds)->getTimestamp();
        $sig = hash_hmac('sha256', $objectKey.'|'.$expires, (string) config('app.key'));

        return 'https://r2.local/signed/'.Str::of($objectKey)->replace('%', '%25')
            .'?expires='.$expires
            .'&signature='.$sig;
    }
}
