<?php

namespace Shared\Files;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

/**
 * W3-T7 / FIX1 gate — reveal Google Drive document URL only after session+Policy + IDENTITY_DOC_VIEWED.
 * Event means the app disclosed the link to an authorized actor, not that Drive was opened.
 *
 * @see API_CONTRACTS §6.3
 */
final class DocumentLinkAuditService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function revealLink(int $candidateId, int $candidateDocumentId, int $actorId): string
    {
        if ((int) Auth::id() !== $actorId) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        $actor = User::query()->find($actorId);
        if ($actor === null) {
            throw new AuthorizationException('CANDIDATE_VIEW_FORBIDDEN');
        }

        if (! Gate::forUser($actor)->allows('candidate.view')) {
            throw new AuthorizationException('CANDIDATE_VIEW_FORBIDDEN');
        }

        $document = DB::table('candidate_document')
            ->where('id', $candidateDocumentId)
            ->where('candidate_id', $candidateId)
            ->first();

        if ($document === null) {
            throw ValidationException::withMessages([
                'candidate_document' => 'CANDIDATE_DOCUMENT_NOT_FOUND',
            ]);
        }

        $candidate = DB::table('candidate')->where('id', $candidateId)->first();
        if ($candidate === null) {
            throw ValidationException::withMessages([
                'candidate' => 'CANDIDATE_NOT_FOUND',
            ]);
        }

        if ($candidate->pii_anonymized_at !== null || $candidate->deleted_at !== null) {
            throw ValidationException::withMessages([
                'candidate' => 'CANDIDATE_NOT_ACCESSIBLE',
            ]);
        }

        $docType = $this->resolveDocType((int) $document->jenis_dokumen_id);
        $viewerRole = $actor->getRoleNames()->sort()->values()->implode(', ');

        $this->audit->record(
            actionType: ActionType::IDENTITY_DOC_VIEWED,
            entityType: 'candidate',
            entityId: $candidateId,
            detail: [
                'candidate_id' => $candidateId,
                'candidate_document_id' => $candidateDocumentId,
                'doc_type' => $docType,
                'viewer_role' => $viewerRole !== '' ? $viewerRole : 'unknown',
            ],
            actorId: $actorId,
        );

        return (string) $document->url_dokumen;
    }

    private function resolveDocType(int $jenisDokumenId): string
    {
        $row = DB::table('jenis_dokumen')->where('id', $jenisDokumenId)->first();

        return $row !== null ? (string) $row->code : 'UNKNOWN';
    }
}
