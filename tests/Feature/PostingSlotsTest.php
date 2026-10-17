<?php

use App\Models\User;
use App\Models\Account;
use App\Models\Workspace;
use Laravel\Passport\Passport;
use App\Services\WorkspaceOwner;
use App\Mcp\Servers\SendaeServer;
use App\Mcp\Tools\SavePostingSlots;

test('posting slots can be replaced and cleared through MCP and read through the API', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);
    $account = Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'Test account', 'timezone' => 'UTC', 'slots' => [['day' => 1, 'time' => '12:00']], 'credentials' => ['access_token' => 'private-token']]);
    $payload = ['workspace_id' => $user->workspace_id, 'id' => $account->id, 'timezone' => 'Europe/London', 'slots' => [['day' => 0, 'time' => '09:30'], ['day' => 6, 'time' => '18:00']]];

    SendaeServer::tool(SavePostingSlots::class, $payload)->assertOk()->assertSee('09:30')->assertDontSee('private-token')->assertDontSee('credentials');

    expect($account->fresh()->slots)->toBe($payload['slots']);
    expect($account->fresh()->timezone)->toBe('Europe/London');
    $this->getJson('/api/state')->assertJsonPath('accounts.0.slots', $payload['slots']);

    SendaeServer::tool(SavePostingSlots::class, [...$payload, 'slots' => []])->assertOk();
    expect($account->fresh()->slots)->toBe([]);

    $this->postJson('/api/account', $payload)->assertOk()->assertJsonPath('slots', $payload['slots']);
    expect($account->fresh()->slots)->toBe($payload['slots']);
});

test('MCP rejects invalid posting slots without changing the account', function (array $invalid): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $account = Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'Test account', 'timezone' => 'UTC', 'slots' => [['day' => 1, 'time' => '10:00']]]);
    $payload = ['workspace_id' => $user->workspace_id, 'id' => $account->id, 'timezone' => 'Europe/London', 'slots' => []];

    SendaeServer::tool(SavePostingSlots::class, [...$payload, ...$invalid])->assertHasErrors();

    expect($account->fresh()->only(['timezone', 'slots']))->toBe(['timezone' => 'UTC', 'slots' => [['day' => 1, 'time' => '10:00']]]);
})->with([
    'invalid timezone' => [['timezone' => 'Invalid/Zone']],
    'invalid day' => [['slots' => [['day' => 7, 'time' => '09:00']]]],
    'negative day' => [['slots' => [['day' => -1, 'time' => '09:00']]]],
    'invalid time' => [['slots' => [['day' => 1, 'time' => '24:00']]]],
    'missing time' => [['slots' => [['day' => 1]]]],
    'null slots' => [['slots' => null]],
    'too many slots' => [['slots' => array_fill(0, 101, ['day' => 1, 'time' => '09:00'])]],
    'missing workspace' => [['workspace_id' => null]],
]);

test('MCP cannot change posting slots in another workspace or another user account', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $otherWorkspace = Workspace::create(['user_id' => $user->id, 'name' => 'Other workspace', 'icon' => 'O']);
    app(WorkspaceOwner::class)->select($otherWorkspace->id);
    $account = Account::create(['provider' => 'x', 'provider_id' => '123', 'name' => 'Test account', 'timezone' => 'UTC', 'slots' => []]);
    $payload = ['workspace_id' => $user->workspace_id, 'id' => $account->id, 'timezone' => 'Europe/London', 'slots' => [['day' => 1, 'time' => '09:00']]];

    SendaeServer::tool(SavePostingSlots::class, $payload)->assertHasErrors();
    SendaeServer::tool(SavePostingSlots::class, [...$payload, 'workspace_id' => $otherWorkspace->id])->assertOk();
    $this->actingAs(User::factory()->create());
    SendaeServer::tool(SavePostingSlots::class, [...$payload, 'workspace_id' => $otherWorkspace->id])->assertHasErrors(['Choose a workspace id']);
    SendaeServer::tool(SavePostingSlots::class, [...$payload, 'workspace_id' => auth()->user()->workspace_id, 'slots' => []])->assertHasErrors();

    expect(Account::withoutGlobalScopes()->findOrFail($account->id)->slots)->toBe($payload['slots']);
});

test('posting slot tool is discoverable and requires authentication over HTTP', function (): void {
    require base_path('routes/ai.php');
    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    $this->postJson('/mcp', $payload)->assertUnauthorized();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $response = $this->postJson('/mcp', $payload)->assertOk();

    expect(collect($response->json('result.tools'))->firstWhere('name', 'save_posting_slots'))->not->toBeNull();
});
