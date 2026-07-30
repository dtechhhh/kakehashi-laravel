<?php

namespace Modules\Candidates\Exceptions;

use Exception;

/**
 * Soft warning BR-DUP-05: matches found; submit may continue with confirm_similarity=true.
 *
 * @phpstan-type Match array{candidate_id: int, nomor_induk: ?string, score: float}
 */
final class SimilarityConfirmationRequired extends Exception
{
    /**
     * @param  list<Match>  $matches
     */
    public function __construct(
        public readonly array $matches,
    ) {
        parent::__construct('DUP_WARN');
    }
}
