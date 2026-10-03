<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| MCP helpers (tests/Feature/Mcp)
|--------------------------------------------------------------------------
| Tokens are obtained through the REAL OAuth flow (dynamic client registration -> consent -> code
| exchange with PKCE), so every MCP test also exercises the scope narrowing the consent screen does.
*/

// Passport needs an RSA keypair to sign tokens. Generated once per process and fed through config, so
// neither tests nor CI need key files on disk (storage/*.key is gitignored; prod injects env secrets).
function mcpKeys(): void
{
    static $keys = null;
    if ($keys === null) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $private);
        $keys = [$private, openssl_pkey_get_details($res)['key']];
    }
    config(['passport.private_key' => $keys[0], 'passport.public_key' => $keys[1]]);
}

/** A family whose $modules are enabled, with $members users (first is admin), active in session. */
function mcpFamily(array $modules = [], int $members = 1): array
{
    mcpKeys();
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ($modules as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }

    return [$family, $users];
}

/** Register a public MCP client the way Claude does. Returns its client_id. */
function mcpClient(string $redirect = 'https://claude.ai/api/mcp/auth_callback'): string
{
    return test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])
        ->assertCreated()->json('client_id');
}

/** PKCE authorize query for $clientId. Returns [query, verifier]. */
function mcpAuthorizeQuery(string $clientId, string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [http_build_query([
        'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
        'scope' => 'mcp:use', 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]), $verifier];
}

/**
 * Full connect: register, consent with $access for $familyId, exchange the code.
 * Returns the token response (access_token, refresh_token, ...).
 */
function mcpConnect(User $user, int $familyId, array $access = []): array
{
    $clientId = mcpClient();
    [$query, $verifier] = mcpAuthorizeQuery($clientId);
    test()->actingAs($user)->get("/oauth/authorize?{$query}")->assertOk();
    $location = test()->actingAs($user)->post('/oauth/authorize', [
        'auth_token' => session('authToken'), 'family_id' => $familyId, 'access' => $access,
    ])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

    // The token endpoint is a fresh, session-less client request.
    auth()->forgetGuards();

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $params['code'],
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_verifier' => $verifier,
    ])->assertOk()->json();
}

/** One JSON-RPC call to /mcp with a bearer token. Returns the decoded response. */
function mcpCall(string $token, string $method, array $params = []): TestResponse
{
    auth()->forgetGuards(); // never let a previous actingAs() leak into a bearer request

    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/** Call a tool; returns [isError, decoded JSON payload or text]. */
function mcpTool(string $token, string $name, array $args = []): array
{
    // A JSON-RPC level error (e.g. an unknown/unlisted tool) comes back as HTTP 400 with an `error` body.
    $res = mcpCall($token, 'tools/call', ['name' => $name, 'arguments' => (object) $args])->json();
    if (isset($res['error'])) {
        return [true, $res['error']['message']];
    }
    $text = $res['result']['content'][0]['text'] ?? '';
    $decoded = json_decode($text, true);

    return [(bool) ($res['result']['isError'] ?? false), $decoded ?? $text];
}

/** Names of the tools the token sees. */
function mcpToolNames(string $token): array
{
    return collect(mcpCall($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();
}
