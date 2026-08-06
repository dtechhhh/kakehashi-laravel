<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Public\GuestCandidateReadModel;
use Modules\GuestAccess\Services\GuestAccessService;
use Tests\TestCase;

/**
 * W6-T7 — response-level PII leak suite.
 *
 * HIDE fields must not appear anywhere in guest responses (list or detail),
 * even though G3 may legitimately show the whitelisted name/photo/work/
 * education. Sort/filter parameters that target PII or HIDE columns are
 * ignored. Raw tokens never reach logs (security log, access log, audit).
 */
class GuestPiiLeakTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    private const PII_EMAIL = 'leak-check@example.com';

    private const PII_PHONE = '+62-812-0000-LEAK';

    private const PII_LINE = 'line-id-LEAK-777';

    private const PII_ADDRESS = 'JL-LEAK-1';

    private const PII_DOB = '1998-05-14';

    private const PII_PASSPORT = 'PASSPORT-LEAK-99';

    private const PII_FAMILY = 'FAMILY-LEAK-NAME';

    private const PII_DOC = 'https://drive.google.com/file/d/LEAK-DOC';

    private const PII_IQ = 'IQ-LEAK-999';

    private const PII_PSIKOTES = 'PSIKOTES-LEAK-TEXT';

    private const PII_VIDEO = 'https://www.youtube.com/watch?v=LEAKVIDEO';

    private const PII_PHYSICAL = 'LEAK-HEIGHT';

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_g2_list_response_contains_no_hide_fields_or_names(): void
    {
        $candidateId = $this->createLeakyCandidate();

        $response = $this->guestGet(route('guest.candidates'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('K-2026-', $html);

        foreach ([
            'W6 Test Candidate',
            'テスト コウホ',
            self::PII_EMAIL,
            self::PII_PHONE,
            self::PII_LINE,
            self::PII_ADDRESS,
            self::PII_DOB,
            self::PII_PASSPORT,
            self::PII_FAMILY,
            self::PII_DOC,
            self::PII_IQ,
            self::PII_PSIKOTES,
            self::PII_VIDEO,
            self::PII_PHYSICAL,
            'LEAK-COMPANY',
            'LEAK-SCHOOL',
            'candidate_immigration',
            'candidate_family',
            'candidate_document',
            'final_laporan_psikotes',
        ] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html, "G2 leaked [{$fragment}].");
        }
    }

    public function test_g3_detail_keeps_whitelist_but_never_hide_fields(): void
    {
        $candidateId = $this->createLeakyCandidate();

        $response = $this->guestGet(route('guest.detail', $candidateId));

        $response->assertOk();
        $html = $response->getContent();

        // G3 whitelist is allowed (v0.3.11).
        $this->assertStringContainsString('W6 Test Candidate', $html);
        $this->assertStringContainsString('LEAK-COMPANY', $html);
        $this->assertStringContainsString('LEAK-SCHOOL', $html);

        foreach ([
            self::PII_EMAIL,
            self::PII_PHONE,
            self::PII_LINE,
            self::PII_ADDRESS,
            self::PII_DOB,
            self::PII_PASSPORT,
            self::PII_FAMILY,
            self::PII_DOC,
            self::PII_IQ,
            self::PII_PSIKOTES,
            self::PII_VIDEO,
            self::PII_PHYSICAL,
            'status_approval',
            'parent_candidate_id',
            'created_by',
            'version',
            'catatan_penolakan',
        ] as $fragment) {
            $this->assertStringNotContainsString($fragment, $html, "G3 leaked [{$fragment}].");
        }
    }

    public function test_pii_sort_and_filter_parameters_are_ignored(): void
    {
        $this->createParticipant($this->containerId);

        $response = $this->guestGet(route('guest.candidates', [
            'sort' => 'nama_alphabet',
            'direction' => 'desc',
            'filter' => 'email',
            'nama' => 'x',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('K-2026-', (string) $response->getContent());
    }

    public function test_raw_tokens_never_reach_logs(): void
    {
        $logPath = storage_path('logs/security.log');
        $baseline = is_file($logPath) ? (int) filesize($logPath) : 0;
        $tokens = [];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $token = str_repeat(dechex($attempt), 64);
            $tokens[] = $token;
            $this->get(route('guest.gate', $token))->assertNotFound();
        }

        $newContent = is_file($logPath)
            ? substr((string) file_get_contents($logPath), $baseline)
            : '';

        foreach ($tokens as $token) {
            $this->assertStringNotContainsString($token, $newContent, 'Raw token leaked into security log.');
        }

        foreach (DB::table('guest_access_log')->get() as $row) {
            foreach ((array) $row as $value) {
                $this->assertFalse(is_string($value) && str_contains($value, $tokens[0]));
            }
        }
    }

    public function test_serialized_guest_payloads_never_carry_internal_candidate_fields(): void
    {
        $candidateId = $this->createLeakyCandidate();

        $listPayload = json_encode(app(GuestCandidateReadModel::class)
            ->listForContainer(app(GuestAccessService::class)
                ->enter($this->approveLink()['token'], null))
            ->items());
        $detailPayload = json_encode(app(GuestCandidateReadModel::class)
            ->detailForGuest(
                app(GuestAccessService::class)->currentSession(),
                $candidateId,
            ));

        foreach ([$listPayload, $detailPayload] as $payload) {
            $this->assertIsString($payload);
            foreach ([
                'status_approval',
                'status_ketersediaan',
                'parent_candidate_id',
                'created_by',
                'approved_by',
                'version',
                'deleted_at',
                'pii_anonymized_at',
                self::PII_EMAIL,
                self::PII_PHONE,
                self::PII_LINE,
            ] as $fragment) {
                $this->assertStringNotContainsString($fragment, $payload, "Serialized guest payload leaked [{$fragment}].");
            }
        }
    }

    private function createLeakyCandidate(): int
    {
        $candidateId = $this->createParticipant($this->containerId, [
            'nama_alphabet' => 'W6 Test Candidate',
            'nama_katakana' => 'テスト コウホ',
            'tanggal_lahir' => self::PII_DOB,
            'alamat_detail' => self::PII_ADDRESS,
            'email' => self::PII_EMAIL,
            'phone' => self::PII_PHONE,
            'line_id' => self::PII_LINE,
            'catatan_tambahan' => 'INTERNAL-NOTE-LEAK',
        ]);

        DB::table('candidate_physical')->insert([
            'candidate_id' => $candidateId,
            'tinggi_cm' => 170.5,
            'berat_kg' => 65,
            'catatan_kesehatan' => self::PII_PHYSICAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('candidate_immigration')->insert([
            'candidate_id' => $candidateId,
            'nomor_paspor' => self::PII_PASSPORT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('candidate_family')->insert([
            'candidate_id' => $candidateId,
            'status_keluarga_id' => $this->lookupType('status_keluarga', 'LEAK_FAMILY_STATUS'),
            'nama' => self::PII_FAMILY,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $familyContactStatusId = $this->lookupType('status_keluarga', 'LEAK_CONTACT_STATUS');
        DB::table('candidate_family_contact')->insert([
            'candidate_id' => $candidateId,
            'status_keluarga_id' => $familyContactStatusId,
            'nama' => self::PII_FAMILY,
            'phone' => self::PII_PHONE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentTypeId = $this->lookupType('jenis_dokumen', 'LEAK_DOC');
        DB::table('candidate_document')->insert([
            'candidate_id' => $candidateId,
            'jenis_dokumen_id' => $documentTypeId,
            'url_dokumen' => self::PII_DOC,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->addVideos($candidateId, self::PII_VIDEO, self::PII_VIDEO);
        DB::table('candidate_self_promo')->where('candidate_id', $candidateId)->update([
            'skor_iq' => 110,
            'skor_matematika' => 90,
            'final_laporan_psikotes' => self::PII_PSIKOTES,
        ]);
        $this->addWorkHistory($candidateId, 'LEAK_WORK', 'LEAK-WORK-FIELD', company: 'LEAK-COMPANY');
        $this->addEducation($candidateId, 'LEAK_EDU', '大学', institution: 'LEAK-SCHOOL');

        return $candidateId;
    }

    private function guestGet(string $uri)
    {
        $this->get(route('guest.gate', $this->approveLink()['token']))->assertRedirect(route('guest.candidates'));

        return $this->get($uri);
    }

    private function lookupType(string $table, string $code): int
    {
        $existing = DB::table($table)->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table($table)->insertGetId([
            'code' => $code,
            'label_id' => 'Dokumen Leak',
            'label_ja' => 'リーク書類',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
