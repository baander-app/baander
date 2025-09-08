<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuth\Client;
use App\Models\TokenAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Put;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Middleware;

/**
 * OAuth Client Management Controller
 *
 * Manages OAuth 2.0 clients including creation, updating, and deletion.
 * Handles different client types: confidential, public, device, etc.
 *
 * @tags OAuth
 */
#[Prefix('oauth/clients')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
])]
class ClientController extends Controller
{
    /**
     * List all OAuth clients
     *
     * Returns all OAuth clients for the current user or system.
     *
     * @param Request $request
     *
     * @response array<array{
     *   id: string,
     *   name: string,
     *   redirect: string,
     *   personal_access_client: boolean,
     *   password_client: boolean,
     *   device_client: boolean,
     *   confidential: boolean,
     *   first_party: boolean,
     *   revoked: boolean,
     *   created_at: string,
     *   updated_at: string
     * }>
     */
    #[Get('/', 'oauth.clients.index')]
    public function index(Request $request): JsonResponse
    {
        $clients = Client::where('revoked', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($clients->makeHidden(['secret']));
    }

    /**
     * Create a new OAuth client
     *
     * Creates a new OAuth 2.0 client with the specified configuration.
     *
     * @param Request $request Request with client configuration
     *
     * @response array{
     *   id: string,
     *   name: string,
     *   secret: string,
     *   redirect: string,
     *   personal_access_client: boolean,
     *   password_client: boolean,
     *   device_client: boolean,
     *   confidential: boolean,
     *   first_party: boolean
     * }
     */
    #[Post('/', 'oauth.clients.store')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'redirect' => 'required|url',
            'personal_access_client' => 'boolean',
            'password_client' => 'boolean',
            'device_client' => 'boolean',
            'confidential' => 'boolean',
            'first_party' => 'boolean',
        ]);

        $client = Client::create([
            'id' => Str::uuid(),
            'name' => $validated['name'],
            'secret' => $validated['confidential'] ?? true ? Str::random(40) : null,
            'redirect' => $validated['redirect'],
            'personal_access_client' => $validated['personal_access_client'] ?? false,
            'password_client' => $validated['password_client'] ?? false,
            'device_client' => $validated['device_client'] ?? false,
            'confidential' => $validated['confidential'] ?? true,
            'first_party' => $validated['first_party'] ?? false,
        ]);

        return response()->json($client, 201);
    }

    /**
     * Get a specific OAuth client
     *
     * Returns details for a specific OAuth client.
     *
     * @param Client $client The OAuth client
     *
     * @response array{
     *   id: string,
     *   name: string,
     *   redirect: string,
     *   personal_access_client: boolean,
     *   password_client: boolean,
     *   device_client: boolean,
     *   confidential: boolean,
     *   first_party: boolean,
     *   revoked: boolean,
     *   created_at: string,
     *   updated_at: string
     * }
     */
    #[Get('{client}', 'oauth.clients.show')]
    public function show(Client $client): JsonResponse
    {
        return response()->json($client->makeHidden(['secret']));
    }

    /**
     * Update an OAuth client
     *
     * Updates an existing OAuth client configuration.
     *
     * @param Request $request Request with updated client data
     * @param Client $client The OAuth client to update
     *
     * @response array{
     *   id: string,
     *   name: string,
     *   redirect: string,
     *   personal_access_client: boolean,
     *   password_client: boolean,
     *   device_client: boolean,
     *   confidential: boolean,
     *   first_party: boolean
     * }
     */
    #[Put('{client}', 'oauth.clients.update')]
    public function update(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'redirect' => 'url',
            'personal_access_client' => 'boolean',
            'password_client' => 'boolean',
            'device_client' => 'boolean',
            'first_party' => 'boolean',
        ]);

        $client->update($validated);

        return response()->json($client->makeHidden(['secret']));
    }

    /**
     * Delete an OAuth client
     *
     * Revokes an OAuth client, preventing it from being used for new requests.
     *
     * @param Client $client The OAuth client to revoke
     *
     * @response array{
     *   message: string
     * }
     */
    #[Delete('{client}', 'oauth.clients.destroy')]
    public function destroy(Client $client): JsonResponse
    {
        $client->update(['revoked' => true]);

        return response()->json([
            'message' => 'Client revoked successfully'
        ]);
    }

    /**
     * Regenerate client secret
     *
     * Generates a new secret for a confidential OAuth client.
     *
     * @param Client $client The OAuth client
     *
     * @response array{
     *   secret: string
     * }
     */
    #[Post('{client}/regenerate-secret', 'oauth.clients.regenerate-secret')]
    public function regenerateSecret(Client $client): JsonResponse
    {
        if (!$client->confidential) {
            return response()->json([
                'message' => 'Cannot regenerate secret for public client'
            ], 400);
        }

        $client->update([
            'secret' => Str::random(40)
        ]);

        return response()->json([
            'secret' => $client->secret
        ]);
    }
}
