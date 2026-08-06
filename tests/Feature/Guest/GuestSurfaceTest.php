<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W6-T6 — guest surface headers and scoped photo assets.
 *
 * Guest pages are JP-only, Cache-Control no-store, and carry the security
 * header set from MODULE_GUEST_ACCESS §7/§8. The photo endpoint only mints
 * signed URLs inside a valid session, scoped to the session container, with a
 * 15-minute TTL. Documents never pass through this endpoint.
 */
class GuestSurfaceTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    private const EXPECTED_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload',
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_gate_redirects_valid_token_and_pages_carry_headers_and_ja_locale(): void
    {
        ['token' => $token] = $this->approveLink(code: null);

        $this->get(route('guest.gate', $token))
            ->assertRedirect(route('guest.candidates'));

        $candidates = $this->get(route('guest.candidates'))->assertOk();
        $this->assertHeaders($candidates);
        $this->assertSame('ja', app()->getLocale());
        $candidates->assertSee('lang="ja"', escape: false);
        $candidates->assertSee('候補者コード', escape: false);
    }

    public function test_invalid_token_gets_generic_japanese_denied_page(): void
    {
        $response = $this->get(route('guest.gate', str_repeat('0', 64)));

        $response->assertNotFound();
        $response->assertSee('リンクを確認できません', escape: false);
        $response->assertDontSee('有効期限切れ');
        $response->assertDontSee('見つかりません');
        $this->assertHeaders($response);
    }

    public function test_code_required_link_shows_code_form_and_correct_code_enters(): void
    {
        ['token' => $token] = $this->approveLink(code: 'WA-12345');

        $this->get(route('guest.gate', $token))
            ->assertOk()
            ->assertSee('追加コード', escape: false);

        $this->post(route('guest.code', $token), ['code' => 'WA-12345'])
            ->assertRedirect(route('guest.candidates'));

        $this->get(route('guest.candidates'))->assertOk();
    }

    public function test_wrong_code_denied_generically(): void
    {
        ['token' => $token] = $this->approveLink(code: 'WA-12345');

        $this->post(route('guest.code', $token), ['code' => 'nope'])
            ->assertNotFound()
            ->assertSee('リンクを確認できません', escape: false);
    }

    public function test_invalid_attempts_over_ten_get_throttled_429(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->get(route('guest.gate', str_repeat(dechex($attempt), 64)))->assertNotFound();
        }

        $response = $this->get(route('guest.gate', str_repeat('f', 64)));
        $response->assertStatus(429);
        $response->assertSee('リンクを確認できません', escape: false);
        $this->assertHeaders($response);
    }

    public function test_photo_route_requires_session_and_scope(): void
    {
        $candidateId = $this->createParticipant($this->containerId);
        $this->addPhoto($candidateId);

        // No session yet → generic denial.
        $this->get(route('guest.photo', $candidateId))->assertNotFound();

        ['token' => $token] = $this->approveLink();
        $this->get(route('guest.gate', $token))->assertRedirect(route('guest.candidates'));

        $response = $this->get(route('guest.photo', $candidateId));
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('expires=', $location);

        $expires = (int) Str::after($location, 'expires=');
        $ttl = $expires - now()->getTimestamp();
        $this->assertGreaterThanOrEqual(850, $ttl, 'Photo URL must live ~15 minutes.');
        $this->assertLessThanOrEqual(900, $ttl);
    }

    public function test_photo_route_denied_for_out_of_scope_and_anonymized_candidates(): void
    {
        $otherContainerId = $this->createGuestContainer();
        $otherCandidateId = $this->createParticipant($otherContainerId);
        $this->addPhoto($otherCandidateId);

        $anonymizedId = $this->createParticipant($this->containerId, ['pii_anonymized_at' => now()]);
        $this->addPhoto($anonymizedId);

        ['token' => $token] = $this->approveLink();
        $this->get(route('guest.gate', $token))->assertRedirect(route('guest.candidates'));

        $this->get(route('guest.photo', $otherCandidateId))->assertNotFound();
        $this->get(route('guest.photo', $anonymizedId))->assertNotFound();
    }

    public function test_photo_route_denied_when_candidate_has_no_photo(): void
    {
        $candidateId = $this->createParticipant($this->containerId);

        ['token' => $token] = $this->approveLink();
        $this->get(route('guest.gate', $token))->assertRedirect(route('guest.candidates'));

        $this->get(route('guest.photo', $candidateId))->assertNotFound();
    }

    private function assertHeaders($response): void
    {
        foreach (self::EXPECTED_HEADERS as $name => $expected) {
            if ($expected === null) {
                $this->assertNotNull($response->headers->get($name), "Missing header [{$name}].");
            } else {
                $this->assertSame($expected, $response->headers->get($name));
            }
        }
    }
}
