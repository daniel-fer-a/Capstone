<?php

namespace Tests\Feature;

use App\Mail\ApplicationReceived;
use App\Mail\SponsorshipInvitation;
use App\Models\AdmissionApplication;
use App\Models\AdmissionIntake;
use App\Models\SponsorInvitation;
use App\Models\User;
use App\Services\NotificationOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SponsorshipIntakeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();
        config(['admissions.require_member_sponsors' => true, 'admissions.sponsorships_enabled' => true]);
        Storage::fake('admissions-private');
    }

    private function member(string $email, array $changes = []): User
    {
        $user = User::query()->create(['name' => 'Socio ficticio', 'email' => $email, 'password' => 'Solo-pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => 'socio',
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(), ...$changes,
        ]);
        return $user;
    }

    private function data(string $legal = 'natural'): array
    {
        $data = ['membership' => 'member', 'legal' => $legal, 'email' => 'postulante@example.test',
            'region' => 'La Araucanía', 'commune' => 'Temuco', 'aiActivity' => 'Investigación y desarrollo en IA.',
            'sponsorEmails' => ['socio1@example.test', 'socio2@example.test'], 'declaration_accepted' => true, 'sharing_accepted' => true];
        return $legal === 'natural' ? [...$data, 'givenNames' => 'Persona', 'familyNames' => 'Ficticia', 'age' => 30, 'identifier' => 'IDENTIDAD-PRIVADA']
            : [...$data, 'organizationName' => 'Organización ficticia', 'organizationId' => 'RUT-ORGANIZACION',
                'representativeName' => 'Representante Ficticia', 'representativeId' => 'RUT-REPRESENTANTE'];
    }

    private function create(string $legal = 'natural', ?array $data = null, ?string $key = null)
    {
        return $this->postJson('/api/v1/intakes', $data ?? $this->data($legal), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function credentials(array $data): array
    {
        return ['reference' => $data['reference'], 'access_token' => $data['access_token']];
    }

    private function acceptBoth(array $data): array
    {
        $members = [];
        foreach (['socio1@example.test', 'socio2@example.test'] as $email) {
            $member = User::query()->where('email', $email)->firstOrFail();
            $members[] = $member;
            $invitation = SponsorInvitation::query()->where('intake_id', $data['id'])->where('email', $email)->whereNull('superseded_at')->firstOrFail();
            Sanctum::actingAs($member);
            $this->postJson('/api/v1/sponsor/invitations/'.$invitation->id.'/decision', ['decision' => 'accepted', 'confirmation' => true])->assertOk();
        }
        return $members;
    }

    private function ready(string $legal = 'natural'): array
    {
        $this->member('socio1@example.test');
        $this->member('socio2@example.test');
        $data = $this->create($legal)->assertCreated()->json('data');
        $this->acceptBoth($data);
        return $data;
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name.'.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    public function test_creation_is_private_idempotent_and_does_not_create_membership(): void
    {
        $this->member('socio1@example.test');
        $key = (string) Str::uuid();
        $first = $this->create(key: $key)->assertCreated()->assertJsonPath('data.stage', 'waiting_sponsors')->assertJsonPath('data.ready_for_documents', false);
        $again = $this->create(key: $key)->assertCreated()->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.access_token'), $again->json('data.access_token'));
        $this->assertSame($first->json('data.reference'), $again->json('data.reference'));
        $first->assertJsonPath('data.invitations.0.state', 'pending')->assertJsonPath('data.invitations.1.state', 'pending')
            ->assertJsonMissingPath('data.invitations.0.target_user_id')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('cchia_admissions.intake_requests', 1);
        $this->assertDatabaseCount('cchia_admissions.sponsor_invitations', 2);
        $this->assertDatabaseCount('cchia_admissions.notification_outbox', 1);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertDatabaseCount('public.users', 1);
        $intake = AdmissionIntake::query()->firstOrFail();
        $this->assertStringNotContainsString('IDENTIDAD-PRIVADA', $intake->encrypted_payload);
        $this->assertSame(hash('sha256', $first->json('data.access_token')), $intake->access_token_hash);
        $this->assertNotSame($first->json('data.access_token'), $intake->encrypted_access_token);
    }

    public function test_creation_rejects_reused_payload_keys_and_duplicated_or_self_sponsors(): void
    {
        $key = (string) Str::uuid();
        $this->create(key: $key)->assertCreated();
        $this->create(data: [...$this->data(), 'commune' => 'Otra comuna'], key: $key)->assertConflict();
        foreach ([['SOCIO1@EXAMPLE.TEST', 'socio1@example.test'], ['postulante@example.test', 'socio2@example.test'], ['malformado', []]] as $emails) {
            $this->create(data: [...$this->data(), 'sponsorEmails' => $emails])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cchia_admissions.intake_requests', 1);
    }

    public function test_owner_access_requires_both_private_credentials_and_never_uses_query_secrets(): void
    {
        $data = $this->create()->assertCreated()->json('data');
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk()->assertJsonPath('data.payload.identifier', 'IDENTIDAD-PRIVADA')
            ->assertJsonMissingPath('data.access_token_hash')->assertJsonMissingPath('data.encrypted_payload');
        $wrong = $this->postJson('/api/v1/intakes/resume', [...$this->credentials($data), 'access_token' => str_repeat('x', 64)])->assertNotFound();
        $missing = $this->postJson('/api/v1/intakes/resume', ['reference' => 'CCHIA-PRE-'.str_repeat('0', 26), 'access_token' => str_repeat('x', 64)])->assertNotFound();
        $this->assertSame($wrong->json(), $missing->json());
        $this->getJson('/api/v1/intakes/resume?reference='.$data['reference'])->assertMethodNotAllowed();
        $this->postJson('/api/v1/intakes/resume?access_token='.str_repeat('a', 64), $this->credentials($data))->assertUnprocessable();
    }

    public function test_documents_cannot_be_submitted_before_two_explicit_confirmations(): void
    {
        $data = $this->create('juridica')->assertCreated()->json('data');
        $this->post('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true,
            'documents' => ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('sponsors');
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertCount(0, Storage::disk('admissions-private')->allFiles());
    }

    public function test_member_only_sees_assigned_minimal_information_and_cannot_answer_for_another(): void
    {
        $one = $this->member('socio1@example.test');
        $two = $this->member('socio2@example.test');
        $data = $this->create()->assertCreated()->json('data');
        $invitation = SponsorInvitation::query()->where('target_user_id', $one->id)->firstOrFail();
        Sanctum::actingAs($two);
        $list = $this->getJson('/api/v1/sponsor/invitations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.applicant_name', 'Persona Ficticia');
        $list->assertDontSee('IDENTIDAD-PRIVADA')->assertDontSee($data['access_token'])->assertDontSee('postulante@example.test')->assertJsonMissingPath('data.0.payload');
        $this->postJson('/api/v1/sponsor/invitations/'.$invitation->id.'/decision', ['decision' => 'accepted', 'confirmation' => true])->assertNotFound();
        $this->assertDatabaseHas('cchia_admissions.sponsor_invitations', ['id' => $invitation->id, 'state' => 'pending']);
    }

    public function test_confirmation_is_explicit_attributed_idempotent_and_cannot_be_rewritten(): void
    {
        $one = $this->member('socio1@example.test');
        $this->create()->assertCreated();
        $invitation = SponsorInvitation::query()->where('target_user_id', $one->id)->firstOrFail();
        Sanctum::actingAs($one);
        $url = '/api/v1/sponsor/invitations/'.$invitation->id.'/decision';
        $this->postJson($url, ['decision' => 'accepted'])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'accepted', 'confirmation' => true])->assertOk();
        $this->postJson($url, ['decision' => 'accepted', 'confirmation' => true])->assertOk();
        $this->postJson($url, ['decision' => 'declined'])->assertConflict();
        $this->assertDatabaseHas('cchia_admissions.sponsor_invitations', ['id' => $invitation->id, 'state' => 'accepted', 'decided_by_user_id' => $one->id]);
        $this->assertSame(1, DB::table('cchia_admissions.intake_events')->where('action', 'sponsorship.accepted')->count());
    }

    public function test_expired_or_revoked_members_cannot_confirm_or_keep_the_gate_open(): void
    {
        $data = $this->ready();
        $one = User::query()->where('email', 'socio1@example.test')->firstOrFail();
        DB::table('cchia_m02.role_assignments')->where('user_id', $one->id)->update(['ends_at' => now()]);
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk()->assertJsonPath('data.ready_for_documents', false)
            ->assertJsonPath('data.invitations.0.state', 'confirmation_unavailable');
        $this->postJson('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true])->assertUnprocessable()->assertJsonValidationErrors('sponsors');
        Sanctum::actingAs($one);
        $this->getJson('/api/v1/sponsor/invitations')->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
    }

    public function test_decline_and_renewal_preserve_history_and_retries_do_not_reset_new_confirmations(): void
    {
        $one = $this->member('socio1@example.test');
        $this->member('socio2@example.test');
        $data = $this->create()->assertCreated()->json('data');
        $original = SponsorInvitation::query()->where('target_user_id', $one->id)->firstOrFail();
        Sanctum::actingAs($one);
        $this->postJson('/api/v1/sponsor/invitations/'.$original->id.'/decision', ['decision' => 'declined'])->assertOk();
        $key = (string) Str::uuid();
        $body = [...$this->credentials($data), 'sponsorEmails' => $this->data()['sponsorEmails'], 'confirmation' => true];
        $this->postJson('/api/v1/intakes/sponsors', $body, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.ready_for_documents', false);
        $this->acceptBoth($data);
        $this->postJson('/api/v1/intakes/sponsors', $body, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.ready_for_documents', true);
        $this->assertDatabaseCount('cchia_admissions.sponsor_invitations', 4);
        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame('declined', $original->fresh()->state);
        $this->postJson('/api/v1/sponsor/invitations/'.$original->id.'/decision', ['decision' => 'accepted', 'confirmation' => true])->assertNotFound();
    }

    public function test_complete_natural_submission_uses_confirmed_evidence_without_creating_a_member(): void
    {
        $data = $this->ready();
        $body = [...$this->credentials($data), 'declaration_accepted' => true];
        $first = $this->postJson('/api/v1/intakes/submit', $body)->assertCreated()->assertJsonPath('data.local_pilot', false);
        $again = $this->postJson('/api/v1/intakes/submit', $body)->assertCreated()->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.tracking_token'), $again->json('data.tracking_token'));
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
        $this->assertDatabaseCount('cchia_admissions.application_sponsors', 2);
        $this->assertDatabaseCount('public.users', 2);
        $this->assertDatabaseCount('cchia_m02.role_assignments', 2);
        $this->assertDatabaseHas('cchia_admissions.applications', ['intake_id' => $data['id'], 'natural_given_names' => 'Persona', 'natural_family_names' => 'Ficticia', 'natural_age' => 30,
            'address' => 'La Araucanía, Temuco', 'rules_version' => '2026-10-29-staged-3']);
        $this->assertSame(2, DB::table('cchia_admissions.application_sponsors')->whereNotNull('invitation_id')->whereNotNull('confirmed_at')->count());
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk()->assertJsonPath('data.stage', 'submitted')->assertJsonPath('data.application.reference', $first->json('data.reference'));
        $this->assertSame(1, DB::table('cchia_admissions.notification_outbox')->where('kind', 'application_received')->count());
    }

    public function test_legal_submission_requires_exactly_estatutos_and_vigencia_plus_representative_data(): void
    {
        $data = $this->ready('juridica');
        $body = [...$this->credentials($data), 'declaration_accepted' => true];
        $this->postJson('/api/v1/intakes/submit', $body)->assertUnprocessable()->assertJsonValidationErrors(['documents.estatutos', 'documents.vigencia']);
        $this->post('/api/v1/intakes/submit', [...$body, 'documents' => ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia'), 'personeria' => $this->pdf('extra')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $response = $this->post('/api/v1/intakes/submit', [...$body, 'documents' => ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia')]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseHas('cchia_admissions.applications', ['id' => $response->json('data.id'), 'representative_name' => 'Representante Ficticia', 'representative_identifier' => 'RUT-REPRESENTANTE']);
        $this->assertDatabaseCount('cchia_admissions.application_documents', 2);
        $this->assertCount(2, Storage::disk('admissions-private')->allFiles());
        $this->assertDatabaseMissing('cchia_admissions.application_documents', ['kind' => 'personeria']);
    }

    public function test_invalid_file_content_rolls_back_without_losing_confirmations(): void
    {
        $data = $this->ready('juridica');
        $path = tempnam(sys_get_temp_dir(), 'cchia-invalid-');
        file_put_contents($path, 'No es un PDF');
        try {
            $this->post('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true,
                'documents' => ['estatutos' => new UploadedFile($path, 'falso.pdf', 'application/pdf', null, true), 'vigencia' => $this->pdf('vigencia')]], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('documents.estatutos');
        } finally { @unlink($path); }
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertCount(0, Storage::disk('admissions-private')->allFiles());
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk()->assertJsonPath('data.ready_for_documents', true);
    }

    public function test_completed_submission_cannot_replace_its_sponsors_or_silently_change_files(): void
    {
        $data = $this->ready();
        $body = [...$this->credentials($data), 'declaration_accepted' => true];
        $this->postJson('/api/v1/intakes/submit', $body)->assertCreated();
        $this->post('/api/v1/intakes/submit', [...$body, 'documents' => ['antecedentes' => $this->pdf('nuevo')]], ['Accept' => 'application/json'])->assertConflict();
        $this->postJson('/api/v1/intakes/sponsors', [...$this->credentials($data), 'sponsorEmails' => ['socio1@example.test', 'socio2@example.test'], 'confirmation' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
        $this->assertDatabaseCount('cchia_admissions.application_documents', 0);
    }

    public function test_failed_legal_storage_cleans_files_and_keeps_the_confirmed_intake_ready(): void
    {
        $data = $this->ready('juridica');
        $disk = Storage::disk('admissions-private');
        $proxy = \Mockery::mock($disk);
        $writes = 0;
        $proxy->shouldReceive('putFileAs')->twice()->andReturnUsing(function (...$arguments) use ($disk, &$writes) {
            if (++$writes === 2) throw new \RuntimeException('Fallo de escritura ficticio.');
            return $disk->putFileAs(...$arguments);
        });
        Storage::shouldReceive('disk')->with('admissions-private')->andReturn($proxy);
        $this->post('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true,
            'documents' => ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia')]], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertDatabaseCount('cchia_admissions.applications', 0);
        $this->assertDatabaseCount('cchia_admissions.application_documents', 0);
        $this->assertCount(0, $disk->allFiles());
        $this->assertSame(0, DB::table('cchia_admissions.notification_outbox')->where('kind', 'application_received')->count());
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk()->assertJsonPath('data.ready_for_documents', true);
    }

    public function test_recovery_copy_can_expire_without_expiring_private_owner_access(): void
    {
        $key = (string) Str::uuid();
        $data = $this->create(key: $key)->assertCreated()->json('data');
        $this->travel(25)->hours();
        $this->artisan('admissions:purge-submission-secrets')->assertSuccessful();
        $this->create(key: $key)->assertConflict();
        $this->assertSame('', AdmissionIntake::query()->firstOrFail()->encrypted_access_token);
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk();
    }

    public function test_outbox_delivers_invites_and_one_receipt_without_private_codes(): void
    {
        Mail::fake();
        $data = $this->ready();
        $receipt = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true])->assertCreated()->json('data');
        $result = app(NotificationOutbox::class)->process();
        $this->assertSame(['sent' => 1, 'failed' => 0, 'abandoned' => 2], $result);
        Mail::assertSent(ApplicationReceived::class, 1);
        Mail::assertNotSent(SponsorshipInvitation::class);
        $application = AdmissionApplication::query()->findOrFail($receipt['id']);
        $text = (new ApplicationReceived($application))->render();
        $this->assertStringNotContainsString($receipt['tracking_token'], $text);
        $this->assertStringNotContainsString($data['access_token'], $text);
        $this->assertStringNotContainsString('IDENTIDAD-PRIVADA', $text);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'abandoned' => 0], app(NotificationOutbox::class)->process());
    }

    public function test_pending_invitation_mail_contains_only_minimal_data_and_an_authenticated_link(): void
    {
        Mail::fake();
        $this->member('socio1@example.test');
        $this->member('socio2@example.test');
        $data = $this->create()->assertCreated()->json('data');
        $this->assertSame(2, app(NotificationOutbox::class)->process()['sent']);
        Mail::assertSent(SponsorshipInvitation::class, 2);
        $text = (new SponsorshipInvitation('Persona Ficticia'))->render();
        $this->assertStringContainsString('/area/patrocinios', $text);
        $this->assertStringNotContainsString($data['access_token'], $text);
        $this->assertStringNotContainsString('IDENTIDAD-PRIVADA', $text);
    }

    public function test_mail_failure_does_not_undo_a_submission_or_create_duplicate_receipts(): void
    {
        $data = $this->ready();
        $body = [...$this->credentials($data), 'declaration_accepted' => true];
        $this->postJson('/api/v1/intakes/submit', $body)->assertCreated();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Error simulado que no se publica'));
        $this->assertSame(1, app(NotificationOutbox::class)->process()['failed']);
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
        $this->postJson('/api/v1/intakes/submit', $body)->assertCreated()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('cchia_admissions.notification_outbox')->where('kind', 'application_received')->count());
        $this->assertDatabaseHas('cchia_admissions.notification_outbox', ['kind' => 'application_received', 'attempts' => 1, 'last_error_type' => 'RuntimeException']);
    }

    public function test_anonymous_clients_cannot_list_or_respond_to_member_invitations(): void
    {
        $this->member('socio1@example.test');
        $this->create()->assertCreated();
        $invitation = SponsorInvitation::query()->firstOrFail();
        $this->getJson('/api/v1/sponsor/invitations')->assertUnauthorized();
        $this->postJson('/api/v1/sponsor/invitations/'.$invitation->id.'/decision', ['decision' => 'accepted', 'confirmation' => true])->assertUnauthorized();
    }

    public function test_a_disabled_sponsorship_channel_keeps_private_reads_but_stops_new_actions(): void
    {
        $data = $this->create()->assertCreated()->json('data');
        config(['admissions.sponsorships_enabled' => false]);
        $this->create()->assertStatus(503);
        $this->postJson('/api/v1/intakes/submit', [...$this->credentials($data), 'declaration_accepted' => true])->assertStatus(503);
        $this->postJson('/api/v1/intakes/resume', $this->credentials($data))->assertOk();
    }

    private function reviewer(bool $canDecide = true): User
    {
        config(['admissions.review_enabled' => true]);
        $user = User::query()->create(['name' => 'Directorio ficticio', 'email' => 'directorio@example.test', 'password' => 'Pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => 'directorio',
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
        ]);
        foreach (['admissions.read', 'admissions.documents.read', 'admissions.review.start', ...($canDecide ? ['admissions.decisions.record'] : [])] as $permission) {
            DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    private function agreement(string $outcome = 'approved'): array
    {
        return ['outcome' => $outcome, 'reason' => 'Acuerdo ficticio del Directorio para la prueba de integración.',
            'minutes_reference' => 'ACTA-FICTICIA-INTERNA', 'meeting_date' => now('America/Santiago')->toDateString()];
    }

    public function test_a_board_resolution_requires_a_started_review_and_explicit_permission(): void
    {
        $intake = $this->ready();
        $id = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($intake), 'declaration_accepted' => true])->assertCreated()->json('data.id');
        $url = '/api/v1/admin/applications/'.$id;
        $this->postJson($url.'/decision', $this->agreement())->assertForbidden();
        $reviewer = $this->reviewer(false);
        $this->postJson($url.'/start-review')->assertOk();
        $this->postJson($url.'/decision', $this->agreement())->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.board_resolutions', 0);
        DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => 'admissions.decisions.record']);
        $this->postJson($url.'/decision', $this->agreement())->assertOk()->assertJsonPath('data.recorded_by_user_id', $reviewer->id);
    }

    public function test_an_approval_is_attributed_immutable_and_does_not_grant_membership(): void
    {
        $intake = $this->ready();
        $receipt = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($intake), 'declaration_accepted' => true])->assertCreated()->json('data');
        $reviewer = $this->reviewer();
        $url = '/api/v1/admin/applications/'.$receipt['id'];
        $this->postJson($url.'/decision', $this->agreement())->assertConflict();
        $this->postJson($url.'/start-review')->assertOk();
        $this->postJson($url.'/decision', $this->agreement())->assertOk();
        $this->postJson($url.'/decision', $this->agreement())->assertOk();
        $this->postJson($url.'/decision', $this->agreement('rejected'))->assertConflict();
        $this->assertDatabaseHas('cchia_admissions.applications', ['id' => $receipt['id'], 'status' => 'approved', 'decision_by_user_id' => $reviewer->id]);
        $this->assertDatabaseCount('cchia_admissions.board_resolutions', 1);
        $this->assertSame(1, DB::table('cchia_admissions.application_events')->where('action', 'application.decision_recorded')->count());
        $this->assertDatabaseCount('public.users', 3);
        $this->assertDatabaseCount('cchia_m02.role_assignments', 3);
        $this->getJson($url)->assertOk()->assertJsonPath('data.resolution.minutes_reference', 'ACTA-FICTICIA-INTERNA');
    }

    public function test_private_tracking_includes_the_reason_but_not_internal_acta_or_actor_details(): void
    {
        $intake = $this->ready();
        $receipt = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($intake), 'declaration_accepted' => true])->assertCreated()->json('data');
        $this->reviewer();
        $url = '/api/v1/admin/applications/'.$receipt['id'];
        $this->postJson($url.'/start-review')->assertOk();
        $this->postJson($url.'/decision', $this->agreement('rejected'))->assertOk();
        $this->postJson('/api/v1/applications/track', ['reference' => $receipt['reference'], 'tracking_token' => $receipt['tracking_token']])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision.reason', $this->agreement()['reason'])
            ->assertJsonCount(3, 'data.history')->assertDontSee('ACTA-FICTICIA-INTERNA')->assertDontSee('directorio@example.test');
    }

    public function test_future_sessions_or_missing_agreement_details_are_rejected(): void
    {
        $intake = $this->ready();
        $id = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($intake), 'declaration_accepted' => true])->assertCreated()->json('data.id');
        $this->reviewer();
        $url = '/api/v1/admin/applications/'.$id;
        $this->postJson($url.'/start-review')->assertOk();
        $this->postJson($url.'/decision', [...$this->agreement(), 'meeting_date' => now('America/Santiago')->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('meeting_date');
        $this->postJson($url.'/decision', [...$this->agreement(), 'reason' => '', 'minutes_reference' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'minutes_reference']);
        $this->assertDatabaseCount('cchia_admissions.board_resolutions', 0);
    }

    public function test_an_expired_reviewer_loses_the_ability_to_record_an_agreement(): void
    {
        $intake = $this->ready();
        $id = $this->postJson('/api/v1/intakes/submit', [...$this->credentials($intake), 'declaration_accepted' => true])->assertCreated()->json('data.id');
        $reviewer = $this->reviewer();
        $url = '/api/v1/admin/applications/'.$id;
        $this->postJson($url.'/start-review')->assertOk();
        DB::table('cchia_m02.role_assignments')->where('user_id', $reviewer->id)->update(['ends_at' => now()]);
        $this->postJson($url.'/decision', $this->agreement())->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.board_resolutions', 0);
    }
}
