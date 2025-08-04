<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'name' => 'Test User',
        ]);
    }

    /** @test */
    public function it_can_login_with_valid_credentials()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'accessToken' => [
                    'token',
                    'expires_at',
                ],
                'refreshToken' => [
                    'token',
                    'expires_at',
                ],
                'sessionId',
            ]);

        // Verify tokens were created
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'access_token',
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'refresh_token',
        ]);
    }

    /** @test */
    public function it_fails_login_with_invalid_credentials()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    /** @test */
    public function it_fails_login_with_nonexistent_user()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    /** @test */
    public function it_can_register_new_user()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'accessToken' => [
                    'token',
                    'expires_at',
                ],
                'refreshToken' => [
                    'token',
                    'expires_at',
                ],
                'sessionId',
            ]);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => 'New User',
        ]);
    }

    /** @test */
    public function it_can_refresh_token()
    {
        // Create refresh token
        $refreshToken = $this->user->createToken(
            'refresh_token',
            ['issue-access-token']
        );

        $response = $this->postJson('/api/auth/refreshToken', [], [
            'Authorization' => 'Bearer ' . $refreshToken->plainTextToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'accessToken' => [
                    'token',
                    'expires_at',
                ],
            ]);
    }

    /** @test */
    public function it_can_get_user_tokens()
    {
        $accessToken = $this->user->createToken('access_token', ['access-api']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/auth/tokens');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'ip_address',
                    'last_used_at',
                    'created_at',
                    'is_current',
                ],
            ]);
    }

    /** @test */
    public function it_can_revoke_specific_token()
    {
        $token1 = $this->user->createToken('token1');
        $token2 = $this->user->createToken('token2');

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/auth/tokens/{$token1->accessToken->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token1->accessToken->id,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token2->accessToken->id,
        ]);
    }

    /** @test */
    public function it_cannot_revoke_current_token()
    {
        $token = $this->user->createToken('current_token');

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/auth/tokens/{$token->accessToken->id}");

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Cannot revoke current session. Use logout instead.',
            ]);
    }

    /** @test */
    public function it_can_logout()
    {
        $token = $this->user->createToken('access_token');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/auth/logout');

        $response->assertStatus(204);
    }
}