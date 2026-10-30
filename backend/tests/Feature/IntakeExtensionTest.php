<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntakeExtensionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();
        Storage::fake('admissions-private');
        config(['admissions.require_member_sponsors' => true, 'admissions.review_enabled' => false]);
    }

    private function data(): array
    {
        return ['membership' => 'member', 'legal' => 'natural', 'givenNames' => 'Persona',
            'familyNames' => 'de prueba', 'age' => 30, 'identifier' => 'Prueba', 'email' => 'persona@example.test',
            'region' => 'La Araucanía', 'commune' => 'Temuco', 'aiActivity' => 'Investigación en IA.', 'declaration_accepted' => true];
    }

    private function account(string $role = 'socio', array $changes = []): User
    {
        $user = User::query()->create(['name' => 'Cuenta ficticia', 'email' => Str::uuid().'@example.test', 'password' => 'Pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([...[
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => $role,
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
        ], ...$changes]);

        return $user;
    }

    private function validData(): array
    {
        return [...$this->data(), 'sponsors' => [$this->account()->id, $this->account()->id]];
    }

    private function pilot(): void
    {
        config(['admissions.require_member_sponsors' => false]);
    }

    private function reviewer(bool $start = true): User
    {
        $user = $this->account('directorio');
        foreach (['admissions.read', ...($start ? ['admissions.review.start'] : [])] as $permission) {
            DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
        }
        config(['admissions.review_enabled' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_strict_natural_member_requires_age_names_and_two_sponsors(): void
    {
        $data = $this->data();
        unset($data['age'], $data['familyNames']);
        $this->postJson('/api/v1/applications', $data)->assertUnprocessable()->assertJsonValidationErrors(['age', 'familyNames', 'sponsors']);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_strict_natural_member_persists_separate_fields_and_verified_sponsors(): void
    {
        $this->postJson('/api/v1/applications', $this->validData())->assertCreated()->assertJsonPath('data.local_pilot', false);
        $this->assertDatabaseHas('cchia_admissions.applications', ['natural_given_names' => 'Persona', 'natural_family_names' => 'de prueba',
            'natural_name' => 'Persona de prueba', 'natural_age' => 30, 'region' => 'La Araucanía', 'commune' => 'Temuco',
            'address' => 'La Araucanía, Temuco', 'rules_version' => '2026-10-28-member-2']);
        $this->assertDatabaseCount('cchia_admissions.application_sponsors', 2);
        $this->assertDatabaseCount('public.users', 2);
    }

    public function test_duplicate_sponsor_ids_with_different_case_are_rejected(): void
    {
        $data = $this->validData();
        $data['sponsors'][1] = strtoupper($data['sponsors'][0]);
        $this->postJson('/api/v1/applications', $data)->assertUnprocessable()->assertJsonValidationErrors('sponsors.0');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_natural_sponsors_must_have_a_current_membership(): void
    {
        $data = [...$this->data(), 'sponsors' => [$this->account('socio', ['ends_at' => now()])->id, $this->account()->id]];
        $this->postJson('/api/v1/applications', $data)->assertUnprocessable()->assertJsonValidationErrors('sponsors.0');
    }

    public function test_age_and_nested_malformed_identity_are_validation_errors(): void
    {
        $this->pilot();
        foreach ([-1, '20.5', 'texto', []] as $age) {
            $this->postJson('/api/v1/applications', [...$this->data(), 'age' => $age])->assertUnprocessable()->assertJsonValidationErrors('age');
        }
        $this->postJson('/api/v1/applications', [...$this->data(), 'givenNames' => ['malformado'], 'region' => ['malformado']])->assertUnprocessable();
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_pilot_intake_is_explicitly_marked_and_closed_outside_local_testing(): void
    {
        $this->pilot();
        $this->postJson('/api/v1/applications', $this->data())->assertCreated()->assertJsonPath('data.local_pilot', true);
        $this->getJson('/api/v1/admissions/capabilities')->assertOk()->assertJsonPath('data.local_pilot', true);
        $this->app['env'] = 'production';
        $this->getJson('/api/v1/admissions/capabilities')->assertOk()->assertJsonPath('data.natural_submission_enabled', false);
        $this->postJson('/api/v1/applications', $this->data())->assertUnprocessable()->assertJsonValidationErrors('sponsors');
    }

    public function test_a_repeated_submission_recovers_one_application_and_one_file(): void
    {
        $this->pilot();
        $data = $this->data();
        $data['documents']['antecedentes'] = UploadedFile::fake()->createWithContent('respaldo.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/applications', $data, $headers)->assertCreated();
        $again = $this->postJson('/api/v1/applications', $data, $headers)->assertCreated()->assertJsonPath('data.replayed', true);
        foreach (['id', 'reference', 'tracking_token'] as $field) $this->assertSame($first->json('data.'.$field), $again->json('data.'.$field));
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
        $this->assertDatabaseCount('cchia_admissions.application_events', 1);
        $this->assertCount(1, Storage::disk('admissions-private')->allFiles());
        $submission = DB::table('cchia_admissions.application_submissions')->first();
        $this->assertNotSame($first->json('data.tracking_token'), $submission->encrypted_tracking_token);
    }

    public function test_the_same_submission_key_cannot_silently_create_an_altered_application(): void
    {
        $this->pilot();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/applications', $this->data(), $headers)->assertCreated();
        $this->postJson('/api/v1/applications', [...$this->data(), 'commune' => 'Otra comuna'], $headers)->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
    }

    public function test_an_expired_recovery_key_cannot_create_a_second_application(): void
    {
        $this->pilot();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/applications', $this->data(), $headers)->assertCreated();
        $this->travel(25)->hours();
        $this->postJson('/api/v1/applications', $this->data(), $headers)->assertConflict();
        $this->artisan('admissions:purge-submission-secrets')->assertSuccessful();
        $this->assertSame('', DB::table('cchia_admissions.application_submissions')->value('encrypted_tracking_token'));
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
    }

    public function test_tracking_requires_the_secret_and_never_exposes_private_data_or_internal_events(): void
    {
        $this->pilot();
        $receipt = $this->postJson('/api/v1/applications', $this->data())->assertCreated();
        $application = AdmissionApplication::query()->findOrFail($receipt->json('data.id'));
        $application->events()->create(['actor_kind' => 'system', 'action' => 'internal.private_note', 'outcome' => 'success',
            'metadata' => ['private' => 'Nota interna que no debe ser pública'], 'occurred_at' => now()]);
        $this->postJson('/api/v1/applications/track', ['reference' => $application->reference, 'tracking_token' => str_repeat('x', 64)])->assertNotFound();
        $this->postJson('/api/v1/applications/track', ['reference' => $application->reference, 'tracking_token' => $receipt->json('data.tracking_token')])
            ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonCount(1, 'data.history')
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.documents')->assertJsonMissingPath('data.natural_name')
            ->assertDontSee('Nota interna')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reading_an_application_does_not_start_its_review(): void
    {
        $this->pilot();
        $id = $this->postJson('/api/v1/applications', $this->data())->assertCreated()->json('data.id');
        $this->reviewer();
        $this->getJson('/api/v1/admin/applications')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/applications/'.$id)->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertDatabaseCount('cchia_admissions.application_events', 1);
        $this->postJson('/api/v1/admin/applications/'.$id.'/start-review')->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson('/api/v1/admin/applications/'.$id.'/start-review')->assertOk();
        $this->assertDatabaseCount('cchia_admissions.application_events', 2);
        $this->assertDatabaseHas('cchia_admissions.application_events', ['action' => 'application.review_started', 'actor_kind' => 'institutional']);
    }

    public function test_read_permission_does_not_grant_permission_to_start_review(): void
    {
        $this->pilot();
        $id = $this->postJson('/api/v1/applications', $this->data())->assertCreated()->json('data.id');
        $this->reviewer(false);
        $this->getJson('/api/v1/admin/applications')->assertOk();
        $this->postJson('/api/v1/admin/applications/'.$id.'/start-review')->assertForbidden();
        config(['admissions.review_enabled' => false]);
        $this->getJson('/api/v1/admin/applications')->assertForbidden();
    }
    public function test_null_characters_are_rejected_before_postgresql_or_password_hashing(): void
    {
        $this->pilot();
        $this->postJson('/api/v1/applications', [...$this->data(), 'aiActivity' => "Actividad\0inválida"])
            ->assertUnprocessable()->assertJsonValidationErrors('aiActivity');
        $this->postJson('/api/v1/applications/track', ['reference' => "CCHIA-\0PRUEBA", 'tracking_token' => str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson('/api/v1/session/login', ['email' => 'persona@example.test', 'password' => "Clave-local-123\0"])->assertUnprocessable();
        $this->postJson('/api/v1/session/reset-password', ['email' => 'persona@example.test', 'token' => 'Prueba',
            'password' => "Clave-local-123\0", 'password_confirmation' => "Clave-local-123\0"])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

}
