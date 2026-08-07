<?php

namespace Modules\GuestAccess\Services;

use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\GuestSession;
use Shared\Files\FileStorageService;

/**
 * W6-T6 — face-photo signed URLs for G3.
 *
 * The URL is minted only inside a validated guest session and only for a
 * candidate in the session container; TTL is capped at 15 minutes
 * (FileStorageService default). Documents are Google Drive links handled by
 * the read model whitelist — they never pass through this endpoint.
 */
final class GuestPhotoService
{
    public function __construct(private readonly FileStorageService $files) {}

    /**
     * @throws GuestAccessDeniedException generic denial for out-of-scope,
     *                                    anonymized, or photo-less candidates
     */
    public function signedPhotoUrl(GuestSession $session, int $candidateId): string
    {
        $visible = DB::table('participation as p')
            ->join('candidate as c', 'c.id', '=', 'p.candidate_id')
            ->where('p.interview_container_id', $session->containerId)
            ->where('c.id', $candidateId)
            ->whereNull('c.deleted_at')
            ->whereNull('c.pii_anonymized_at')
            ->whereNull('c.parent_candidate_id')
            ->whereNotNull('c.nomor_induk')
            ->exists();

        if (! $visible) {
            throw new GuestAccessDeniedException;
        }

        $photo = DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();
        if ($photo === null) {
            throw new GuestAccessDeniedException;
        }

        return $this->files->temporaryUrl((string) $photo->object_key);
    }
}
