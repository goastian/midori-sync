<?php

namespace Tests\Feature\Library;

use App\Models\Library\LibraryCollection;
use App\Models\User;
use App\Services\SyncAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_picker_is_scoped_bounded_and_searchable(): void
    {
        $user = User::factory()->create();
        $token = app(SyncAuthService::class)->createSessionToken($user)['token'];
        for ($index = 0; $index < 31; $index++) {
            LibraryCollection::create(['user_id' => $user->id, 'name' => sprintf('Reading %02d', $index)]);
        }
        $literal = LibraryCollection::create(['user_id' => $user->id, 'name' => '100% saved']);
        LibraryCollection::create(['user_id' => User::factory()->create()->id, 'name' => 'Foreign collection']);

        $first = $this->withToken($token)->getJson('/api/library/v1/collections')->assertOk()
            ->assertJsonPath('version', 1)->assertJsonPath('truncated', true)->assertJsonCount(30, 'items');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertNotContains('Foreign collection', array_column($first->json('items'), 'name'));

        $found = $this->withToken($token)->getJson('/api/library/v1/collections?q=READING%2030')->assertOk()
            ->assertJsonPath('truncated', false)->assertJsonCount(1, 'items');
        $this->assertSame('Reading 30', $found->json('items.0.name'));
        $this->withToken($token)->getJson('/api/library/v1/collections?q=100%25')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $literal->id);
        $this->withToken($token)->getJson('/api/library/v1/collections?q=Foreign')->assertOk()
            ->assertJsonCount(0, 'items');
        $this->withToken($token)->getJson('/api/library/v1/collections?q='.str_repeat('a', 81))->assertUnprocessable();
    }

    public function test_collection_picker_requires_a_session(): void
    {
        $this->getJson('/api/library/v1/collections')->assertUnauthorized();
    }
}
