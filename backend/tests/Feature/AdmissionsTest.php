<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AdmissionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();
        Storage::fake('admissions-private');
    }

    private function natural(string $membership = 'member'): array
    {
        return ['membership' => $membership, 'legal' => 'natural', 'name' => 'Persona de prueba',
            'identifier' => 'Identificación de prueba', 'email' => 'POSTULANTE@example.org',
            'address' => 'Domicilio de prueba', 'aiActivity' => 'Investigación y desarrollo en IA.',
            'declaration_accepted' => true];
    }

    private function user(): User
    {
        return User::query()->create(['name' => 'Usuario de prueba', 'email' => Str::uuid().'@example.org',
            'password' => 'exclusivo-para-pruebas-automatizadas']);
    }

    private function assignment(User $user, string $role = 'socio', array $changes = []): string
    {
        $id = (string) Str::uuid();
        DB::table('cchia_m02.role_assignments')->insert(array_merge([
            'id' => $id, 'user_id' => $user->id, 'role_code' => $role,
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
        ], $changes));

        return $id;
    }

    private function juridica(array $sponsors = []): array
    {
        if (!$sponsors) {
            $sponsors = [$this->user(), $this->user()];
            foreach ($sponsors as $sponsor) {
                $this->assignment($sponsor);
            }
        }
        $data = $this->natural();
        unset($data['name'], $data['identifier']);

        return [...$data, 'legal' => 'juridica', 'organizationName' => 'Organización de prueba',
            'organizationId' => 'Identificación de organización', 'representativeName' => 'Representante de prueba',
            'representativeId' => 'Identificación del representante',
            'sponsors' => array_map(fn (User $u) => $u->id, $sponsors),
            'documents' => array_combine(['vigencia', 'personeria', 'estatutos'], array_map(
                fn ($name) => UploadedFile::fake()->createWithContent($name.'.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"),
                ['vigencia', 'personeria', 'estatutos'],
            ))];
    }

    private function submit(array $data)
    {
        return $this->post('/api/v1/applications', $data, ['Accept' => 'application/json']);
    }

    private function enableReviewer(): User
    {
        config(['admissions.review_enabled' => true]);
        $user = $this->user();
        $this->assignment($user, 'directorio');
        foreach (['admissions.read', 'admissions.documents.read'] as $permission) {
            DB::table('cchia_m02.role_permissions')->insert(['role_code' => 'directorio', 'permission_code' => $permission]);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_natural_application_is_persisted_without_creating_a_user_or_membership(): void
    {
        $response = $this->submit($this->natural())->assertCreated()->assertJsonPath('data.status', 'received');
        $this->assertDatabaseHas('cchia_admissions.applications', ['id' => $response->json('data.id'), 'email' => 'postulante@example.org']);
        $this->assertDatabaseCount('public.users', 0);
        $this->assertDatabaseCount('cchia_m02.role_assignments', 0);
        $this->assertDatabaseCount('cchia_admissions.application_events', 1);
    }

    public function test_natural_collaborator_is_distinct_from_a_member(): void
    {
        $this->submit($this->natural('collaborator'))->assertCreated();
        $this->assertDatabaseHas('cchia_admissions.applications', ['membership' => 'collaborator', 'legal' => 'natural', 'status' => 'received']);
    }

    public function test_tracking_secret_is_returned_once_and_only_its_hash_is_saved(): void
    {
        $response = $this->submit($this->natural())->assertCreated();
        $token = $response->json('data.tracking_token');
        $application = AdmissionApplication::query()->findOrFail($response->json('data.id'));
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $application->tracking_token_hash);
        $this->assertArrayNotHasKey('tracking_token_hash', $application->toArray());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_identity_fields_cannot_be_mixed_between_person_types(): void
    {
        $this->submit([...$this->natural(), 'organizationName' => 'No corresponde'])->assertUnprocessable()->assertJsonValidationErrors('organizationName');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_client_cannot_assign_status_or_roles(): void
    {
        $this->submit([...$this->natural(), 'status' => 'approved', 'roles' => ['directorio']])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'roles']);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_missing_identity_and_review_confirmation_are_rejected(): void
    {
        $data = $this->natural();
        unset($data['identifier']);
        $data['declaration_accepted'] = false;
        $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors(['identifier', 'declaration_accepted']);
    }

    public function test_company_application_requires_documents_and_two_sponsors(): void
    {
        $data = $this->natural();
        unset($data['name'], $data['identifier']);
        $this->submit([...$data, 'legal' => 'juridica', 'organizationName' => 'Organización',
            'organizationId' => 'id', 'representativeName' => 'Persona', 'representativeId' => 'id'])
            ->assertUnprocessable()->assertJsonValidationErrors(['documents.vigencia', 'documents.personeria', 'documents.estatutos', 'sponsors']);
    }

    public function test_company_application_has_private_documents_and_verified_sponsors(): void
    {
        $response = $this->submit($this->juridica())->assertCreated();
        $application = AdmissionApplication::query()->findOrFail($response->json('data.id'));
        $this->assertCount(3, $application->documents);
        $this->assertCount(2, $application->sponsors);
        foreach ($application->documents as $document) {
            $bytes = Storage::disk('admissions-private')->get($document->storage_key);
            $this->assertSame(hash('sha256', $bytes), $document->sha256);
            $this->assertSame(strlen($bytes), $document->bytes);
            $this->assertStringNotContainsString('public', $document->storage_key);
        }
        $this->assertStringNotContainsString('tracking_token', json_encode($application->events[0]->metadata));
        $this->assertStringNotContainsString('@', json_encode($application->events[0]->metadata));
    }

    public function test_duplicate_sponsors_are_rejected_before_persistence(): void
    {
        $data = $this->juridica();
        $data['sponsors'][1] = $data['sponsors'][0];
        $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors('sponsors.0');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertCount(0, Storage::disk('admissions-private')->allFiles());
    }

    public static function invalidAssignments(): array
    {
        return array_map(fn ($name) => [$name], ['expired', 'future', 'revoked', 'pending', 'role_disabled', 'wrong_role']);
    }

    #[DataProvider('invalidAssignments')]
    public function test_ineffective_members_cannot_sponsor(string $variant): void
    {
        $a = $this->user(); $b = $this->user();
        $changes = match ($variant) {
            'expired' => ['ends_at' => now()], 'future' => ['starts_at' => now()->addDay()],
            'revoked' => ['revoked_at' => now()], 'pending' => ['status' => 'pending'], default => [],
        };
        $this->assignment($a, $variant === 'wrong_role' ? 'directorio' : 'socio', $changes);
        $this->assignment($b);
        if ($variant === 'role_disabled') {
            DB::table('cchia_m02.roles')->where('code', 'socio')->update(['is_active' => false]);
        }
        $this->submit($this->juridica([$a, $b]))->assertUnprocessable()->assertJsonValidationErrors('sponsors.0');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_legal_collaborator_is_disabled_until_its_requirements_are_confirmed(): void
    {
        $data = $this->juridica(); $data['membership'] = 'collaborator';
        $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors('legal');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_legal_collaborator_technical_route_requires_the_pj_evidence_when_enabled(): void
    {
        config(['admissions.collaborator_legal_enabled' => true]);
        $data = $this->juridica(); $data['membership'] = 'collaborator';
        $this->submit($data)->assertCreated();
        $this->assertDatabaseHas('cchia_admissions.applications', ['membership' => 'collaborator', 'legal' => 'juridica']);
    }

    public function test_file_content_is_checked_independently_of_its_pdf_extension(): void
    {
        $data = $this->natural();
        $path = tempnam(sys_get_temp_dir(), 'cchia-invalid-');
        file_put_contents($path, 'Este archivo contiene texto plano.');
        $data['documents']['antecedentes'] = new UploadedFile($path, 'archivo.pdf', null, null, true);
        try {
            $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors('documents.antecedentes');
        } finally {
            unlink($path);
        }
    }

    public function test_files_over_the_design_limit_are_rejected(): void
    {
        $data = $this->natural();
        $data['documents']['antecedentes'] = UploadedFile::fake()->create('archivo.pdf', 5121, 'application/pdf');
        $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors('documents.antecedentes');
    }

    public function test_failed_file_write_rolls_back_metadata_and_removes_the_first_file(): void
    {
        $disk = Storage::disk('admissions-private');
        $proxy = \Mockery::mock($disk);
        $writes = 0;
        $proxy->shouldReceive('putFileAs')->twice()->andReturnUsing(function (...$arguments) use ($disk, &$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('Fallo de almacenamiento inyectado para la prueba.');
            }
            return $disk->putFileAs(...$arguments);
        });
        Storage::shouldReceive('disk')->with('admissions-private')->andReturn($proxy);
        $this->submit($this->juridica())->assertStatus(500);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertDatabaseCount('cchia_admissions.application_documents', 0);
        $this->assertCount(0, $disk->allFiles());
    }

    #[Group('native-client')]
    public function test_failed_document_insert_rolls_back_the_application_and_removes_written_files(): void
    {
        DB::unprepared("CREATE FUNCTION cchia_admissions.fail_document_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Fallo inyectado de prueba'; END; \$\$; CREATE TRIGGER injected_failure BEFORE INSERT ON cchia_admissions.application_documents FOR EACH ROW EXECUTE FUNCTION cchia_admissions.fail_document_test();");
        $this->submit($this->juridica())->assertStatus(500);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertDatabaseCount('cchia_admissions.application_documents', 0);
        $this->assertCount(0, Storage::disk('admissions-private')->allFiles());
    }

    public function test_anonymous_requests_cannot_read_an_application_or_its_documents(): void
    {
        $response = $this->submit($this->juridica())->assertCreated();
        $id = $response->json('data.id');
        $document = AdmissionApplication::query()->findOrFail($id)->documents()->firstOrFail();
        $this->getJson('/api/v1/admin/applications/'.$id)->assertUnauthorized();
        $this->getJson('/api/v1/admin/applications/'.$id.'/documents/'.$document->id)->assertUnauthorized();
    }

    public function test_a_role_alone_does_not_grant_sensitive_access(): void
    {
        $id = $this->submit($this->natural())->assertCreated()->json('data.id');
        $user = $this->user(); $this->assignment($user, 'directorio');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/applications/'.$id)->assertForbidden();
        $this->assertDatabaseHas('cchia_m02.authorization_audit_log', ['actor_user_id' => $user->id, 'action' => 'admissions.read']);
    }

    public function test_explicit_permission_and_current_assignment_allow_private_read(): void
    {
        $id = $this->submit($this->juridica())->assertCreated()->json('data.id');
        $this->enableReviewer();
        $this->getJson('/api/v1/admin/applications/'.$id)->assertOk()->assertJsonMissingPath('data.tracking_token_hash')
            ->assertJsonMissingPath('data.documents.0.storage_key');
        $document = AdmissionApplication::query()->findOrFail($id)->documents()->firstOrFail();
        $this->getJson('/api/v1/admin/applications/'.$id.'/documents/'.$document->id)
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_document_cannot_be_requested_under_another_application(): void
    {
        $a = $this->submit($this->juridica())->assertCreated()->json('data.id');
        $b = $this->submit($this->natural())->assertCreated()->json('data.id');
        $document = AdmissionApplication::query()->findOrFail($a)->documents()->firstOrFail();
        $this->enableReviewer();
        $this->getJson('/api/v1/admin/applications/'.$b.'/documents/'.$document->id)->assertNotFound();
    }

    #[Group('native-client')]
    public function test_database_rejects_mutation_of_the_application_history(): void
    {
        $this->submit($this->natural())->assertCreated();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('cchia_admissions.application_events')->update(['outcome' => 'changed']));
    }

    #[Group('native-client')]
    public function test_database_rejects_a_role_in_the_wrong_scope(): void
    {
        $user = $this->user();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->assignment($user, 'lider_comision')); // Requiere commission, no global.
    }

    #[Group('native-client')]
    public function test_database_rejects_company_application_without_its_evidence_at_transaction_end(): void
    {
        $this->expectException(QueryException::class);
        DB::transaction(function (): void {
            DB::table('cchia_admissions.applications')->insert([
            'id' => (string) Str::uuid(), 'reference' => 'CCHIA-INCOMPLETE-TEST',
            'tracking_token_hash' => hash('sha256', 'test-only'), 'membership' => 'member', 'legal' => 'juridica',
            'organization_name' => 'Organización', 'organization_identifier' => 'id',
            'representative_name' => 'Representante', 'representative_identifier' => 'id',
            'email' => 'test@example.org', 'address' => 'Domicilio', 'ai_activity' => 'IA',
            'rules_version' => 'test', 'declaration_accepted_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
}
