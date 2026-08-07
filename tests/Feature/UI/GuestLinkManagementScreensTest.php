<?php

namespace Tests\Feature\UI;

use App\Livewire\Jobs\InterviewDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Jobs\Services\GuestLinkService;
use Tests\Feature\Guest\GuestFixture;
use Tests\TestCase;

/**
 * UI-W6-U1 — Guest link management (Maker request / Checker approve-reject,
 * token-once panel). The raw token is shown exactly once and never re-appears
 * after reload; the panel exposes the full public link for email dispatch.
 */
class GuestLinkManagementScreensTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_maker_can_request_and_checker_can_approve_with_token_once_panel(): void
    {
        $maker = User::findOrFail($this->makerId);
        $checker = User::findOrFail($this->checkerId);

        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $this->containerId, [
            'label' => 'W6-U1 link',
            'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
            'kode_tambahan' => null,
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('guest_link')->count(), 'No token before approval.');

        $component = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $this->containerId])
            ->call('approveGuestLink', (int) $request->getKey())
            ->assertSet('guestToken', fn (?string $token): bool => is_string($token) && strlen($token) === 64);

        $token = (string) $component->get('guestToken');
        $component->assertSee(url('/guest/'.$token), escape: false);
        $this->assertSame(
            hash('sha256', $token),
            DB::table('guest_link')->where('interview_container_id', $this->containerId)->value('token_hash'),
            'Only the SHA-256 hash is stored.',
        );
    }

    public function test_token_is_gone_after_reload_and_never_resent(): void
    {
        $maker = User::findOrFail($this->makerId);
        $checker = User::findOrFail($this->checkerId);

        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $this->containerId, [
            'label' => 'W6-U1 reload link',
            'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
            'kode_tambahan' => null,
            'version' => 0,
        ]);
        $component = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $this->containerId])
            ->call('approveGuestLink', (int) $request->getKey());
        $token = (string) $component->get('guestToken');

        // A fresh mount (page reload) must not re-expose the raw token.
        $fresh = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $this->containerId]);

        $fresh->assertSet('guestToken', null);
        $fresh->assertDontSee($token);
    }

    public function test_checker_can_reject_without_token_generation(): void
    {
        $maker = User::findOrFail($this->makerId);
        $checker = User::findOrFail($this->checkerId);

        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $this->containerId, [
            'label' => 'W6-U1 reject link',
            'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
            'kode_tambahan' => null,
            'version' => 0,
        ]);

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $this->containerId])
            ->call('startGuestReject', (int) $request->getKey())
            ->set('guestRejectNote', 'Tidak diperlukan')
            ->call('rejectGuestLink', (int) $request->getKey())
            ->assertRedirect(route('jobs.show', $this->containerId));

        $this->assertSame(0, DB::table('guest_link')->count());
        $this->assertSame(
            'rejected',
            DB::table('pending_request')->where('id', $request->getKey())->value('status'),
        );
    }
}
