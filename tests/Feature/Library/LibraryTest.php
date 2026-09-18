<?php

namespace Tests\Feature\Library;

use App\Models\Library\BillingEvent;
use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['library.billing_enabled' => false]);
        config(['queue.default' => 'sync']);
        $this->user = User::factory()->create();
        $this->token = app(SyncAuthService::class)->createSessionToken($this->user)['token'];
    }

    public function test_store_and_dedupe_link(): void
    {
        $r1 = $this->withToken($this->token)->postJson('/api/library/links', [
            'url' => 'https://example.com/a?utm_source=x',
            'title' => 'A',
            'tags' => ['Paper', 'paper'],
        ]);
        $r1->assertCreated()->assertJsonPath('canonical_url', 'https://example.com/a');
        $this->assertCount(1, $r1->json('tags'));

        $r2 = $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'https://example.com/a']);
        $r2->assertOk()->assertJsonPath('id', $r1->json('id'));
        $this->assertEquals(1, LibraryLink::count());
    }

    public function test_rejects_non_public_url(): void
    {
        $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'http://localhost:8080/x'])
            ->assertStatus(422);
        $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'file:///etc/passwd'])
            ->assertStatus(422);
    }

    public function test_plan_limit_returns_402_with_upgrade_url(): void
    {
        config(['library.billing_enabled' => true]);
        config(['library.upgrade_url' => 'https://payments.astian.org/checkout?plan=pro']);
        Cache::put("ent:{$this->user->id}", [
            'plan' => 'free',
            'status' => 'active',
            'limits' => ['max_links' => 1, 'max_snapshots' => 0, 'snapshot_kinds' => ['html']],
        ], 300);

        $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'https://example.com/1'])->assertCreated();
        $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'https://example.com/2'])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit')->assertJsonPath('upgrade_url', 'https://payments.astian.org/checkout?plan=pro');
    }

    public function test_bulk_export_shares_highlights(): void
    {
        $a = $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'https://example.com/a'])->json('id');
        $b = $this->withToken($this->token)->postJson('/api/library/links', ['url' => 'https://example.com/b'])->json('id');

        $this->withToken($this->token)->postJson('/api/library/links/bulk', [
            'ids' => [$a, $b], 'action' => 'archive',
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->withToken($this->token)->getJson('/api/library/links?is_archived=1')->assertOk()->assertJsonCount(2, 'data');

        $h = $this->withToken($this->token)->postJson("/api/library/links/{$a}/highlights", [
            'quote' => 'clave', 'note' => 'releer',
        ])->assertCreated()->json();

        $s = $this->withToken($this->token)->postJson("/api/library/links/{$a}/shares", [])->assertCreated()->json();
        $this->getJson('/api/library/s/'.$s['token'])->assertOk()->assertJsonPath('url', 'https://example.com/a');

        $this->withToken($this->token)->getJson('/api/library/links/export?format=netscape')
            ->assertOk()->assertHeader('content-disposition');

        $this->assertDatabaseHas('library_highlights', ['id' => $h['id']]);
    }

    public function test_webhook_validates_hmac_and_is_idempotent(): void
    {
        config(['library.payments_webhook_secret' => 's3cret']);
        $payload = ['event_id' => 'evt-1', 'type' => 'subscription.created', 'authentik_id' => $this->user->authentik_id, 'plan' => 'pro', 'status' => 'active', 'limits' => ['max_links' => -1]];
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, 's3cret');

        $this->postJson('/api/library/billing/webhook', $payload, ['X-Payments-Signature' => 'bad'])
            ->assertStatus(401);

        $this->call('POST', '/api/library/billing/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENTS_SIGNATURE' => $sig,
            'HTTP_X_PAYMENTS_EVENT' => 'subscription.created',
            'HTTP_X_PAYMENTS_EVENT_ID' => 'evt-1',
        ], $body)->assertOk();

        $this->assertDatabaseHas('billing_cache', ['user_id' => $this->user->id, 'plan' => 'pro']);

        // Replay of the same event_id → deduped, no duplicates.
        $this->call('POST', '/api/library/billing/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENTS_SIGNATURE' => $sig,
            'HTTP_X_PAYMENTS_EVENT' => 'subscription.created',
            'HTTP_X_PAYMENTS_EVENT_ID' => 'evt-1',
        ], $body)->assertOk()->assertJsonPath('deduped', true);
        $this->assertEquals(1, BillingEvent::where('event_id', 'evt-1')->count());
    }

    public function test_guardrail_no_stripe_in_sync(): void
    {
        // Payments only lives in library/payments. Forbids local Stripe tables or env.
        $this->assertFalse(Schema::hasTable('subscriptions'));
        $this->assertStringNotContainsString('STRIPE', file_get_contents(base_path('.env.example')) ?? '');
    }
}
