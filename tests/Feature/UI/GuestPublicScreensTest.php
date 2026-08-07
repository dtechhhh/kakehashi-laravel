<?php

namespace Tests\Feature\UI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Guest\GuestFixture;
use Tests\TestCase;

/**
 * UI-W6-U2 — Guest public pages (gate, G2 list, G3 detail).
 *
 * JP-only, no-store, whitelist-only. The list is pseudonymous; the detail
 * shows the whitelisted name/photo/work/education; HIDE fields never render.
 */
class GuestPublicScreensTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_full_guest_flow_renders_jp_pseudonym_list_and_whitelist_detail(): void
    {
        $candidateId = $this->createParticipant($this->containerId, [
            'nama_alphabet' => 'Yamada Taro',
            'nama_katakana' => 'ヤマダ タロウ',
            'email' => 'guest-leak@example.com',
        ]);
        $this->addJapaneseLevel($candidateId, 'JLPT_N3', '日本語能力試験N3');
        $this->addBidangDiminati($candidateId, 'FOOD', '食品製造');
        $this->addWorkHistory($candidateId, 'FOOD_WORK', '食品製造業', company: 'フード株式会社');
        $this->addEducation($candidateId, 'UNIV', '大学', institution: '工業大学');
        $this->addPhoto($candidateId);

        $this->get(route('guest.gate', $this->approveLink()['token']))
            ->assertRedirect(route('guest.candidates'));

        $list = $this->get(route('guest.candidates'))->assertOk();
        $listHtml = (string) $list->getContent();
        $this->assertStringContainsString('K-2026-', $listHtml);
        $this->assertStringContainsString('歳', $listHtml);
        $this->assertStringContainsString('男', $listHtml);
        $this->assertStringContainsString('インドネシア', $listHtml);
        $this->assertStringNotContainsString('Yamada Taro', $listHtml, 'G2 must stay pseudonymous.');
        $this->assertStringNotContainsString('guest-leak@example.com', $listHtml);
        $this->assertStringNotContainsString('<img', $listHtml, 'G2 must not render photos.');
        $this->assertGuestHeaders($list);

        $detail = $this->get(route('guest.detail', $candidateId))->assertOk();
        $detailHtml = (string) $detail->getContent();
        $this->assertStringContainsString('Yamada Taro', $detailHtml);
        $this->assertStringContainsString('ヤマダ タロウ', $detailHtml);
        $this->assertStringContainsString('フード株式会社', $detailHtml);
        $this->assertStringContainsString('工業大学', $detailHtml);
        $this->assertStringContainsString(route('guest.photo', $candidateId), $detailHtml);
        $this->assertStringNotContainsString('guest-leak@example.com', $detailHtml);
        $this->assertGuestHeaders($detail);
    }

    public function test_pagination_links_render_server_side(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->createParticipant($this->containerId);
        }

        $this->get(route('guest.gate', $this->approveLink()['token']))->assertRedirect(route('guest.candidates'));

        $pageOne = $this->get(route('guest.candidates'))->assertOk();
        $pageOne->assertSee('page=2', escape: false);

        $this->get(route('guest.candidates', ['page' => 2]))->assertOk();
    }

    public function test_code_required_link_asks_then_enters_via_http(): void
    {
        $token = $this->approveLink(code: 'WA-SECRET-9')['token'];

        $this->get(route('guest.gate', $token))
            ->assertOk()
            ->assertSee('追加コード', escape: false);

        $this->post(route('guest.code', $token), ['code' => 'WA-SECRET-9'])
            ->assertRedirect(route('guest.candidates'));

        $this->get(route('guest.candidates'))->assertOk();
    }

    public function test_expired_and_closed_failures_look_identical(): void
    {
        $expiredToken = $this->approveLink()['token'];
        $this->expireLink();

        $closedToken = $this->approveLink()['token'];
        $this->closeContainer();

        $expiredPage = $this->get(route('guest.gate', $expiredToken))->assertNotFound();
        $closedPage = $this->get(route('guest.gate', $closedToken))->assertNotFound();

        $this->assertSame(
            $this->deniedBody($expiredPage),
            $this->deniedBody($closedPage),
            'Failure pages must not distinguish the reason.',
        );
    }

    private function expireLink(): void
    {
        DB::table('guest_link')
            ->where('interview_container_id', $this->containerId)
            ->update(['tanggal_kadaluarsa' => now()->subMinute()]);
    }

    private function closeContainer(): void
    {
        DB::table('interview_container')
            ->where('id', $this->containerId)
            ->update(['status' => 'Ditutup']);
    }

    private function deniedBody($response): string
    {
        $html = (string) $response->getContent();

        return preg_replace('/\s+/', ' ', $html);
    }

    private function assertGuestHeaders($response): void
    {
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
    }
}
