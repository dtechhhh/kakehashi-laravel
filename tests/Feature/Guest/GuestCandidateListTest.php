<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GuestAccess\Public\GuestCandidateReadModel;
use Modules\GuestAccess\Services\GuestAccessService;
use Tests\TestCase;

/**
 * W6-T4 — G2 pseudonym list.
 *
 * Identifier = Nomor Induk K-YYYY-NNNNN only; no name, photo, work/education
 * history, or any HIDE field. Anonymized and soft-deleted candidates are
 * excluded; scope is always the session container; pagination is server-side
 * (default 25); sort is allowlisted and never touches PII columns.
 */
class GuestCandidateListTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_g2_payload_contains_only_whitelisted_pseudonym_fields(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $this->addJapaneseLevel($candidateId, 'JLPT_N3', '日本語能力試験N3', 'N3 120/180');
        $this->addSswQualification($candidateId, 'FOOD_BEVERAGE', '飲食料品製造業');
        $this->addBidangDiminati($candidateId, 'FOOD', '食品製造');

        $item = $this->firstItem();

        $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $item->nomor_induk);
        $this->assertSame(28, (int) $item->umur);
        $this->assertSame('M', $item->jenis_kelamin);
        $this->assertSame('インドネシア', $item->kewarganegaraan);
        $this->assertSame('食品製造', $item->bidang_diminati);
        $this->assertSame([['jenis' => '日本語能力試験N3', 'skor' => 'N3 120/180']], $item->japanese_levels);
        $this->assertSame(['飲食料品製造業'], $item->ssw_qualifications);

        $payload = json_encode($item);
        $this->assertIsString($payload);
        foreach (self::HIDE_FRAGMENTS as $fragment) {
            $this->assertStringNotContainsString($fragment, $payload, "HIDE fragment [{$fragment}] leaked into G2.");
        }
    }

    public function test_anonymized_candidate_is_excluded_from_g2(): void
    {
        $visibleId = $this->createParticipant($this->containerId);
        $this->createParticipant($this->containerId, ['pii_anonymized_at' => now()]);

        $items = $this->items();

        $this->assertSame([$visibleId], array_map(static fn (object $item): int => (int) $item->id, $items));
    }

    public function test_soft_deleted_candidate_is_excluded_from_g2(): void
    {
        $visibleId = $this->createParticipant($this->containerId);
        $this->createParticipant($this->containerId, ['deleted_at' => now()]);

        $items = $this->items();

        $this->assertSame([$visibleId], array_map(static fn (object $item): int => (int) $item->id, $items));
    }

    public function test_list_is_scoped_to_session_container_only(): void
    {
        $otherContainerId = $this->createGuestContainer();
        $ownId = $this->createParticipant($this->containerId);
        $otherId = $this->createParticipant($otherContainerId);

        $session = $this->enter();
        $items = $this->readModel()->listForContainer($session)->items();

        $ids = array_map(static fn (object $item): int => (int) $item->id, $items);
        $this->assertContains($ownId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_pagination_defaults_to_twenty_five_server_side(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->createParticipant($this->containerId);
        }

        $session = $this->enter();
        $pageOne = $this->readModel()->listForContainer($session);
        $pageTwo = $this->readModel()->listForContainer($session, ['page' => 2]);

        $this->assertSame(30, $pageOne->total());
        $this->assertCount(25, $pageOne->items());
        $this->assertCount(5, $pageTwo->items());
        $this->assertNotSame(
            array_map(static fn (object $item): int => (int) $item->id, $pageOne->items()),
            array_map(static fn (object $item): int => (int) $item->id, $pageTwo->items()),
        );
    }

    public function test_sort_allowlist_ignores_pii_columns_and_unknown_params(): void
    {
        $older = $this->createParticipant($this->containerId, ['tanggal_lahir' => '1990-01-01']);
        $younger = $this->createParticipant($this->containerId, ['tanggal_lahir' => '2001-07-07']);

        $session = $this->enter();

        $byAgeAsc = $this->readModel()->listForContainer($session, ['sort' => 'umur', 'direction' => 'asc'])->items();
        $this->assertSame([$older, $younger], array_map(static fn (object $item): int => (int) $item->id, $byAgeAsc));

        // Forbidden / unknown sort falls back to the safe default (nomor_induk asc).
        $byName = $this->readModel()->listForContainer($session, ['sort' => 'nama_alphabet', 'direction' => 'asc'])->items();
        $byUnknown = $this->readModel()->listForContainer($session, ['sort' => 'email', 'filter' => 'x', 'foo' => 'bar'])->items();

        $this->assertSame(
            array_map(static fn (object $item): string => $item->nomor_induk, $byUnknown),
            array_map(static fn (object $item): string => $item->nomor_induk, $byName),
        );
        $this->assertCount(2, $byUnknown);
    }

    private const HIDE_FRAGMENTS = [
        'nama_alphabet',
        'nama_katakana',
        'email',
        'phone',
        'line_id',
        'tanggal_lahir',
        'alamat',
        'candidate_work',
        'candidate_education',
        'kewarganegaraan_id',
        'pii_anonymized_at',
        'object_key',
        'url_file',
        'skor_iq',
        'final_laporan_psikotes',
    ];

    private function enter(): object
    {
        ['token' => $token] = $this->approveLink();

        return app(GuestAccessService::class)->enter($token, null);
    }

    private function items(): array
    {
        return $this->readModel()->listForContainer($this->enter())->items();
    }

    private function firstItem(): object
    {
        return $this->items()[0];
    }

    private function readModel(): GuestCandidateReadModel
    {
        return app(GuestCandidateReadModel::class);
    }
}
