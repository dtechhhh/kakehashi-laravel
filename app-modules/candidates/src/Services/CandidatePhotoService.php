<?php

namespace Modules\Candidates\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Files\FileStorageService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * W3-T7 / FIX1 — face photo upload to private R2 + candidate_photo metadata.
 * Documents remain Google Drive URL-only (never uploaded here).
 */
final class CandidatePhotoService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_EDGE_PX = 1024;

    private const JPEG_QUALITY = 85;

    /** @var array<string, string> finfo MIME → storage MIME */
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/webp' => 'image/webp',
    ];

    public function __construct(
        private readonly FileStorageService $files,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate (magic + decode + resize), store on R2, upsert metadata, bump version (CAS).
     *
     * @throws AuthorizationException
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function store(User $actor, int $candidateId, UploadedFile $file, int $expectedVersion): object
    {
        $this->assertActorSession($actor);
        $this->authorizeUpdate($actor);

        $raw = $this->readUpload($file);
        $mime = $this->detectAllowedMime($raw);
        $processed = $this->processImage($raw, $mime);
        $objectKey = $this->buildObjectKey($candidateId, $processed['mime']);

        $this->files->storeCandidatePhoto($objectKey, $processed['bytes'], $processed['mime']);

        $rollbackCleanupRegistered = false;

        try {
            return DB::transaction(function () use (
                $actor,
                $candidateId,
                $objectKey,
                $processed,
                $expectedVersion,
                &$rollbackCleanupRegistered,
            ): object {
                DB::afterRollBack(function () use ($objectKey): void {
                    $this->deleteObjectBestEffort($objectKey);
                });
                $rollbackCleanupRegistered = true;

                $candidate = DB::table('candidate')->where('id', $candidateId)->first();
                if ($candidate === null) {
                    $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
                }

                $this->assertPhotoEditable($candidate);

                if ((int) $candidate->version !== $expectedVersion) {
                    throw new ConflictHttpException('CONFLICT');
                }

                $affected = DB::table('candidate')
                    ->where('id', $candidateId)
                    ->where('version', $expectedVersion)
                    ->whereIn('status_approval', [
                        CandidateApprovalStatus::Draft->value,
                        CandidateApprovalStatus::Ditolak->value,
                    ])
                    ->whereNull('deleted_at')
                    ->whereNull('pii_anonymized_at')
                    ->update([
                        'version' => $expectedVersion + 1,
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw new ConflictHttpException('CONFLICT');
                }

                $previous = DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();
                $previousKey = $previous !== null ? (string) $previous->object_key : null;

                $now = now();
                if ($previous === null) {
                    DB::table('candidate_photo')->insert([
                        'candidate_id' => $candidateId,
                        'object_key' => $objectKey,
                        'mime_type' => $processed['mime'],
                        'size_bytes' => $processed['size'],
                        'uploaded_by' => $actor->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('candidate_photo')->where('candidate_id', $candidateId)->update([
                        'object_key' => $objectKey,
                        'mime_type' => $processed['mime'],
                        'size_bytes' => $processed['size'],
                        'uploaded_by' => $actor->getKey(),
                        'updated_at' => $now,
                    ]);
                }

                $this->audit->record(
                    actionType: ActionType::CANDIDATE_PHOTO_UPLOADED,
                    entityType: 'candidate',
                    entityId: $candidateId,
                    detail: [
                        'candidate_id' => $candidateId,
                        'size_bytes' => $processed['size'],
                        'mime' => $processed['mime'],
                    ],
                    actorId: $actor->getKey(),
                );

                if ($previousKey !== null && $previousKey !== $objectKey) {
                    $this->scheduleDeleteIfUnreferenced($previousKey);
                }

                return DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();
            });
        } catch (Throwable $exception) {
            if (! $rollbackCleanupRegistered) {
                $this->deleteObjectBestEffort($objectKey);
            }

            throw $exception;
        }
    }

    /**
     * Signed URL for an authorized viewer (session actor must match).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function temporaryUrl(
        User $actor,
        int $candidateId,
        int $ttlSeconds = FileStorageService::DEFAULT_SIGNED_TTL_SECONDS,
    ): string {
        $this->assertActorSession($actor);

        if (! Gate::forUser($actor)->allows('candidate.view')) {
            throw new AuthorizationException('CANDIDATE_VIEW_FORBIDDEN');
        }

        $candidate = DB::table('candidate')->where('id', $candidateId)->first();
        if ($candidate === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }
        if ($candidate->pii_anonymized_at !== null || $candidate->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_ACCESSIBLE');
        }

        $photo = DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();
        if ($photo === null) {
            $this->fail('photo', 'CANDIDATE_PHOTO_NOT_FOUND');
        }

        return $this->files->temporaryUrl((string) $photo->object_key, $ttlSeconds);
    }

    /**
     * After successful commit: delete object only if no candidate_photo row still references it.
     * Safe for shared main/revision keys.
     */
    public function scheduleDeleteIfUnreferenced(string $objectKey): void
    {
        $objectKey = ltrim($objectKey, '/');
        if ($objectKey === '') {
            return;
        }

        DB::afterCommit(function () use ($objectKey): void {
            try {
                $this->deleteObjectIfUnreferenced($objectKey);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Immediate unreferenced delete (used by after-commit callbacks and tests).
     */
    public function deleteObjectIfUnreferenced(string $objectKey): void
    {
        $objectKey = ltrim($objectKey, '/');
        if ($objectKey === '') {
            return;
        }

        try {
            if (DB::table('candidate_photo')->where('object_key', $objectKey)->exists()) {
                return;
            }

            $this->files->deleteObject($objectKey);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deleteObjectBestEffort(string $objectKey): void
    {
        try {
            $this->files->deleteObject($objectKey);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function assertActorSession(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }
    }

    private function authorizeUpdate(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('candidate.update')) {
            throw new AuthorizationException('CANDIDATE_UPDATE_FORBIDDEN');
        }
    }

    private function assertPhotoEditable(object $candidate): void
    {
        if ($candidate->pii_anonymized_at !== null || $candidate->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_ACCESSIBLE');
        }

        $status = (string) $candidate->status_approval;
        $editable = in_array($status, [
            CandidateApprovalStatus::Draft->value,
            CandidateApprovalStatus::Ditolak->value,
        ], true);

        if (! $editable) {
            $this->fail('status_approval', 'CANDIDATE_PHOTO_NOT_EDITABLE');
        }
    }

    private function readUpload(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            $this->fail('photo', 'CANDIDATE_PHOTO_INVALID');
        }

        $size = $file->getSize();
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            $this->fail('photo', 'CANDIDATE_PHOTO_TOO_LARGE');
        }

        $path = $file->getRealPath();
        if ($path === false) {
            $this->fail('photo', 'CANDIDATE_PHOTO_INVALID');
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->fail('photo', 'CANDIDATE_PHOTO_INVALID');
        }

        if (strlen($raw) > self::MAX_BYTES) {
            $this->fail('photo', 'CANDIDATE_PHOTO_TOO_LARGE');
        }

        return $raw;
    }

    private function detectAllowedMime(string $raw): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($raw);
        if (! is_string($detected) || ! isset(self::ALLOWED_MIMES[$detected])) {
            $this->fail('photo', 'CANDIDATE_PHOTO_MIME_INVALID');
        }

        return self::ALLOWED_MIMES[$detected];
    }

    /**
     * @return array{bytes: string, mime: string, size: int}
     */
    private function processImage(string $raw, string $mime): array
    {
        if (! extension_loaded('gd')) {
            $this->fail('photo', 'CANDIDATE_PHOTO_GD_REQUIRED');
        }

        try {
            $manager = new ImageManager(new GdDriver);
            $image = $manager->read($raw);
            $image->scaleDown(width: self::MAX_EDGE_PX, height: self::MAX_EDGE_PX);

            $encoded = match ($mime) {
                'image/png' => $image->toPng(),
                'image/webp' => $image->toWebp(self::JPEG_QUALITY),
                default => $image->toJpeg(self::JPEG_QUALITY),
            };

            $bytes = (string) $encoded;
            $outMime = match ($mime) {
                'image/png' => 'image/png',
                'image/webp' => 'image/webp',
                default => 'image/jpeg',
            };

            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                $this->fail('photo', 'CANDIDATE_PHOTO_TOO_LARGE');
            }

            return [
                'bytes' => $bytes,
                'mime' => $outMime,
                'size' => strlen($bytes),
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('photo', 'CANDIDATE_PHOTO_CORRUPT');
        }
    }

    private function buildObjectKey(int $candidateId, string $mime): string
    {
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return sprintf(
            'candidates/%d/photo-%s.%s',
            $candidateId,
            Str::lower(Str::random(16)),
            $ext,
        );
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
