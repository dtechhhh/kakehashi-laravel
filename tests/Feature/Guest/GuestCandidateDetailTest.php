<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Public\GuestCandidateReadModel;
use Modules\GuestAccess\Services\GuestAccessService;
use Shared\Audit\ActionType;
use Tests\TestCase;

/**
 * W6-T5 — G3 detail whitelist (PRD Lampiran C).
 *
 * Only whitelisted fields leave the module; full Candidate object is never
 * serialized. Every successful detail open records GUEST_DETAIL_VIEWED with
 * {token_id, candidate_id, container_id, ip}; anonymized or out-of-scope
 * candidates are denied with the generic exception and produce no audit row.
 */
class GuestCandidateDetailTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_detail_returns_only_whitelisted_fields(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $this->addJapaneseLevel($candidateId, 'JLPT_N3', '日本語能力試験N3');
        $this->addSswQualification($candidateId, 'FOOD', '飲食料品製造業', shareable: true);
        $this->addBidangDiminati($candidateId, 'FOOD', '食品製造');
        $this->addEnglishLevel($candidateId, 'TOEIC', 'TOEIC');
        $this->addDrivingQualification($candidateId, 'SIM_A', '普通自動車免許');
        $this->addWorkHistory($candidateId, 'FOOD_WORK', '食品製造業');
        $this->addEducation($candidateId, 'UNIVERSITY', '大学');
        $this->addPhoto($candidateId);
        $this->addVideos($candidateId, 'https://www.youtube.com/watch?v=JIKO', 'https://www.youtube.com/watch?v=SKILL');

        $detail = $this->detail($this->enter(), $candidateId);

        $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $detail['nomor_induk']);
        $this->assertSame('W6 Test Candidate', $detail['nama_alphabet']);
        $this->assertSame('テスト コウホ', $detail['nama_katakana']);
        $this->assertTrue($detail['photo_available']);
        $this->assertSame([['jenis' => 'TOEIC', 'skor' => 'TOEIC 750']], $detail['english_levels']);
        $this->assertSame(['普通自動車免許'], $detail['driving_qualifications']);
        $this->assertSame(
            [[
                'nama_perusahaan' => 'W6 製造株式会社',
                'perusahaan_penanggung' => 'TSK 株式会社',
                'bidang_pekerjaan' => '食品製造業',
                'tanggal_masuk' => '2020-04-01',
                'tanggal_keluar' => '2024-03-31',
            ]],
            $detail['work_history'],
        );
        $this->assertSame(
            [[
                'jenis_pendidikan' => '大学',
                'jurusan' => '機械工学',
                'nama_institusi' => 'W6 大学',
                'tanggal_masuk' => '2016-04-01',
                'tanggal_keluar' => '2020-03-31',
            ]],
            $detail['education_history'],
        );

        $payload = json_encode($detail);
        $this->assertIsString($payload);
        foreach (self::HIDE_FRAGMENTS as $fragment) {
            $this->assertStringNotContainsString($fragment, $payload, "HIDE fragment [{$fragment}] leaked into G3.");
        }
    }

    public function test_shareable_document_rule_drive_links_only(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $this->addSswQualification($candidateId, 'SHARE_SSW', 'シェア可能SSW', shareable: true);
        DB::table('candidate_qual_ssw')
            ->where('candidate_id', $candidateId)
            ->update(['url_file' => 'https://drive.google.com/file/d/ssw-share/view']);

        $this->addSswQualification($candidateId, 'PRIVATE_SSW', '非シェアSSW', shareable: false);
        DB::table('candidate_qual_ssw')
            ->where('candidate_id', $candidateId)
            ->where('skill_ssw_id', $this->skillId('PRIVATE_SSW'))
            ->update(['url_file' => 'https://drive.google.com/file/d/private/view']);

        $this->addQualOther($candidateId, 'SHARE_OTHER', 'シェア可能その他', shareable: true, url: 'https://drive.google.com/file/d/other-share/view');
        $this->addQualOther($candidateId, 'PRIVATE_OTHER', '非シェアその他', shareable: false, url: 'https://drive.google.com/file/d/other-private/view');

        $detail = $this->detail($this->enter(), $candidateId);

        $urls = array_column($detail['shareable_documents'], 'url');
        $this->assertContains('https://drive.google.com/file/d/ssw-share/view', $urls);
        $this->assertContains('https://drive.google.com/file/d/other-share/view', $urls);
        $this->assertNotContains('https://drive.google.com/file/d/private/view', $urls);
        $this->assertNotContains('https://drive.google.com/file/d/other-private/view', $urls);
    }

    public function test_videos_are_hidden_by_default_even_when_set(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $this->addVideos($candidateId, 'https://www.youtube.com/watch?v=JIKO', 'https://www.youtube.com/watch?v=SKILL');

        $detail = $this->detail($this->enter(), $candidateId);
        $payload = json_encode($detail);

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('youtube', $payload);
        $this->assertArrayNotHasKey('video', $detail);
    }

    public function test_detail_records_guest_detail_viewed_audit(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $session = $this->enter();

        $this->detail($session, $candidateId);

        $row = DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_DETAIL_VIEWED->value)
            ->where('entity_id', $candidateId)
            ->first();
        $this->assertNotNull($row);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->actor_role_snapshot);
        $detail = is_string($row->detail) ? json_decode($row->detail, true) : $row->detail;
        $this->assertIsArray($detail);
        $this->assertSame($session->linkId, (int) ($detail['token_id'] ?? 0));
        $this->assertSame($candidateId, (int) ($detail['candidate_id'] ?? 0));
        $this->assertSame($this->containerId, (int) ($detail['container_id'] ?? 0));
    }

    public function test_anonymized_candidate_detail_is_denied_generically_without_audit(): void
    {
        $candidateId = $this->createParticipant($this->containerId, ['pii_anonymized_at' => now()]);

        try {
            $this->detail($this->enter(), $candidateId);
            $this->fail('Anonymized candidate detail must be denied.');
        } catch (GuestAccessDeniedException $exception) {
            $this->assertSame('GUEST_DENIED', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_DETAIL_VIEWED->value)
            ->count());
    }

    public function test_detail_outside_session_container_is_denied(): void
    {
        $otherContainerId = $this->createGuestContainer();
        $otherCandidateId = $this->createParticipant($otherContainerId);

        $this->expectException(GuestAccessDeniedException::class);
        $this->detail($this->enter(), $otherCandidateId);
    }

    public function test_no_photo_means_no_photo_url(): void
    {
        $candidateId = $this->createParticipant($this->containerId);

        $this->assertFalse($this->detail($this->enter(), $candidateId)['photo_available']);
    }

    private const HIDE_FRAGMENTS = [
        'email',
        'phone',
        'line_id',
        'tanggal_lahir',
        'alamat',
        'tempat_lahir',
        'candidate_family',
        'candidate_immigration',
        'candidate_document',
        'paspor',
        'zairyu',
        'tinggi_cm',
        'berat_kg',
        'skor_iq',
        'skor_matematika',
        'final_laporan_psikotes',
        'catatan',
        'deleted_at',
        'pii_anonymized_at',
        'url_file',
        'video',
    ];

    private function enter(): object
    {
        ['token' => $token] = $this->approveLink();

        return app(GuestAccessService::class)->enter($token, null);
    }

    private function detail(object $session, int $candidateId): array
    {
        return app(GuestCandidateReadModel::class)->detailForGuest($session, $candidateId);
    }

    private function skillId(string $code): int
    {
        return (int) DB::table('skill_ssw')->where('code', $code)->value('id');
    }
}
