<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetAccessPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private function account(): User
    {
        return User::query()->create(['name' => 'Cuenta de prueba', 'email' => 'cuenta@example.test', 'password' => 'Contrasena-local-123']);
    }

    public function test_login_normalizes_email_and_logout_removes_access(): void
    {
        $this->account();
        $this->postJson('/api/v1/session/login', ['email' => ' CUENTA@EXAMPLE.TEST ', 'password' => 'Contrasena-local-123'])
            ->assertOk()->assertJsonPath('data.can_review', false)->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/session/me')->assertOk();
        $this->postJson('/api/v1/session/logout')->assertOk();
        $this->getJson('/api/v1/session/me')->assertUnauthorized();
        $this->assertDatabaseCount('public.users', 1);
    }

    public function test_invalid_credentials_do_not_create_a_user(): void
    {
        $this->postJson('/api/v1/session/login', ['email' => 'inexistente@example.test', 'password' => 'Incorrecta-local-123'])->assertUnprocessable();
        $this->getJson('/api/v1/session/me')->assertUnauthorized();
        $this->assertDatabaseCount('public.users', 0);
    }

    public function test_malformed_email_is_rejected_without_a_server_error(): void
    {
        $this->postJson('/api/v1/session/login', ['email' => ['malformado'], 'password' => 'Incorrecta-local-123'])->assertUnprocessable();
        $this->postJson('/api/v1/session/forgot-password', ['email' => ['malformado']])->assertUnprocessable();
    }

    public function test_reset_requests_return_the_same_message_for_existing_and_unknown_accounts(): void
    {
        Notification::fake();
        $user = $this->account();
        $a = $this->postJson('/api/v1/session/forgot-password', ['email' => $user->email])->assertOk();
        $b = $this->postJson('/api/v1/session/forgot-password', ['email' => 'nadie@example.test'])->assertOk();
        $this->assertSame($a->json(), $b->json());
        Notification::assertSentTo($user, ResetAccessPassword::class, function (ResetAccessPassword $notification) use ($user): bool {
            $message = $notification->toMail($user);
            $this->assertStringContainsString('/restablecer#token=', $message->actionUrl);
            $this->assertStringContainsString('email=', $message->actionUrl);
            $this->assertStringNotContainsString('?token=', $message->actionUrl);
            return true;
        });
    }

    public function test_password_reset_is_single_use_and_revokes_old_api_tokens(): void
    {
        Notification::fake();
        $user = $this->account();
        $user->createToken('Prueba local');
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'Nueva-contrasena-456', 'password_confirmation' => 'Nueva-contrasena-456'];
        $this->postJson('/api/v1/session/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('Nueva-contrasena-456', $user->fresh()->password));
        $this->assertDatabaseCount('public.personal_access_tokens', 0);
        $this->postJson('/api/v1/session/reset-password', $data)->assertUnprocessable();
        $this->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'Contrasena-local-123'])->assertUnprocessable();
        $this->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'Nueva-contrasena-456'])->assertOk();
    }

    public function test_password_reset_rejects_invalid_or_expired_tokens(): void
    {
        $user = $this->account();
        $data = ['email' => $user->email, 'token' => 'invalido', 'password' => 'Nueva-contrasena-456', 'password_confirmation' => 'Nueva-contrasena-456'];
        $this->postJson('/api/v1/session/reset-password', $data)->assertUnprocessable();
        $data['token'] = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/session/reset-password', $data)->assertUnprocessable();
        $this->assertTrue(Hash::check('Contrasena-local-123', $user->fresh()->password));
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/session/login', ['email' => 'inexistente@example.test', 'password' => 'Incorrecta-local-123'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/session/login', ['email' => 'inexistente@example.test', 'password' => 'Incorrecta-local-123'])->assertStatus(429);
    }

    public function test_changing_the_password_invalidates_a_previous_web_session(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'Contrasena-local-123'])->assertOk();
        $this->getJson('/api/v1/session/me')->assertOk();
        $user->forceFill(['password' => 'Otra-contrasena-789'])->save();
        Auth::forgetGuards();
        $this->getJson('/api/v1/session/me')->assertUnauthorized();
    }
    public function test_file_sessions_survive_a_new_guard_and_store_instance(): void
    {
        $path = storage_path('framework/testing/sessions-'.Str::uuid());
        File::ensureDirectoryExists($path);
        config(['session.driver' => 'file', 'session.files' => $path]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        try {
            $user = $this->account();
            $response = $this->postJson('/api/v1/session/login', ['email' => $user->email, 'password' => 'Contrasena-local-123'])->assertOk();
            $sessionId = $response->getCookie(config('session.cookie'))->getValue();
            $this->assertFileExists($path.'/'.$sessionId);
            $this->assertStringContainsString('login_web_', File::get($path.'/'.$sessionId));
            Auth::forgetGuards();
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->getJson('/api/v1/session/me')->assertOk()->assertJsonPath('data.id', $user->id);
        } finally {
            File::deleteDirectory($path);
        }
    }

}
