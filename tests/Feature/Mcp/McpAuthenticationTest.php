<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    config([
        'passport.private_key' => $privateKey,
        'passport.public_key' => openssl_pkey_get_details($key)['key'],
    ]);
});

function initializeRequest(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
        ],
    ];
}

it('requires a bearer token and points clients at the OAuth metadata', function (): void {
    $response = $this->postJson('/mcp', initializeRequest());

    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('serves the MCP server to users with an access token', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $this->postJson('/mcp', initializeRequest())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'devnull');
});

it('advertises Passport as the authorization server', function (): void {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'));
});

it('lets clients register themselves', function (): void {
    $this->postJson(route('mcp.oauth.register'), [
        'client_name' => 'Some Agent',
        'redirect_uris' => ['https://agent.example.com/callback'],
    ])
        ->assertCreated()
        ->assertJsonStructure(['client_id']);
});

it('asks signed in users for consent before authorizing a client', function (): void {
    $user = User::factory()->create();
    $client = resolve(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Some Agent',
        ['https://agent.example.com/callback'],
        confidential: false,
    );

    $this->actingAs($user)
        ->get(route('passport.authorizations.authorize', [
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://agent.example.com/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'abc',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oauth/authorize')
            ->where('client', 'Some Agent')
            ->where('state', 'abc')
            ->where('scopes', ['Use MCP server']));
});

it('lets signed in users approve a client with the token on the consent page', function (): void {
    $user = User::factory()->create();
    $client = resolve(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Some Agent',
        ['http://localhost:5555/callback'],
        confidential: false,
    );

    $page = $this->actingAs($user)->get(route('passport.authorizations.authorize', [
        'client_id' => $client->getKey(),
        'redirect_uri' => 'http://localhost:5555/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'abc',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $payload = $page->viewData('page');

    expect($payload)->not->toHaveKey('deferredProps');

    $props = $payload['props'];

    $response = $this->post(route('passport.authorizations.approve'), [
        'auth_token' => $props['authToken'],
        'client_id' => $props['clientId'],
        'state' => 'abc',
    ]);

    $response->assertRedirectContains('http://localhost:5555/callback?code=');
    expect($response->headers->get('Location'))->toContain('state=abc');
});

it('sends guests to sign in first', function (): void {
    $client = resolve(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Some Agent',
        ['https://agent.example.com/callback'],
        confidential: false,
    );

    $this->get(route('passport.authorizations.authorize', [
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://agent.example.com/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]))->assertRedirectToRoute('home');
});
