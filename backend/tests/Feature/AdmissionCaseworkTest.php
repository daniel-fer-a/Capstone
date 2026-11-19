<?php

namespace Tests\Feature;

use App\Mail\AdmissionUpdate;
use App\Models\AdmissionApplication;
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

class AdmissionCaseworkTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();
        config(['admissions.require_member_sponsors' => true, 'admissions.sponsorships_enabled' => true, 'admissions.formalization_enabled' => true]);
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

    private function reviewer(bool $canDecide = true): User
    {
        config(['admissions.review_enabled' => true]);
        $user = User::query()->create(['name' => 'Directorio ficticio', 'email' => 'directorio@example.test', 'password' => 'Pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => 'directorio',
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
        ]);
        foreach (['admissions.read', 'admissions.documents.read', 'admissions.review.start', ...($canDecide ? ['admissions.decisions.record', 'admissions.corrections.manage', 'admissions.appeals.record', 'admissions.membership.formalize'] : [])] as $permission) {
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


    private function submitted(string $legal = 'natural'): array
    {
        $intake = $this->ready($legal);
        $body = [...$this->credentials($intake), 'declaration_accepted' => true];
        if ($legal === 'juridica') $body['documents'] = ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia')];
        $receipt = $this->post('/api/v1/intakes/submit', $body, ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->reviewer();
        $this->postJson($this->url($receipt).'/start-review')->assertOk();
        return $receipt;
    }

    private function url(array $receipt): string { return '/api/v1/admin/applications/'.$receipt['id']; }
    private function privateAccess(array $receipt): array { return ['reference' => $receipt['reference'], 'tracking_token' => $receipt['tracking_token']]; }
    private function rejected(): array
    {
        $receipt = $this->submitted();
        $this->postJson($this->url($receipt).'/decision', $this->agreement('rejected'))->assertOk();
        return $receipt;
    }
    private function appealBody(array $receipt): array { return [...$this->privateAccess($receipt), 'reason' => 'Solicito el pronunciamiento de la próxima Asamblea.', 'confirmation' => true]; }
    private function assemblyBody(string $outcome = 'approved'): array { return [...$this->agreement($outcome), 'meeting_type' => 'ordinary', 'confirmation' => true]; }
    private function requestDocuments(array $receipt, array $kinds = ['estatutos']): string
    {
        return $this->postJson($this->url($receipt).'/corrections', ['reason' => 'Adjuntar una copia legible.', 'requested_kinds' => $kinds], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.id');
    }
    private function upload(array $receipt, string $id, string $name = 'nuevo')
    {
        return $this->post('/api/v1/applications/corrections/submit', [...$this->privateAccess($receipt), 'correction_id' => $id, 'confirmation' => true, 'documents' => ['estatutos' => $this->pdf($name)]], ['Accept' => 'application/json']);
    }

    public function test_appeal_needs_a_rejection_and_private_credentials(): void
    {
        $receipt = $this->submitted();
        $this->postJson('/api/v1/applications/appeal', $this->appealBody($receipt))->assertConflict();
        $this->postJson($this->url($receipt).'/decision', $this->agreement('rejected'))->assertOk();
        $this->postJson('/api/v1/applications/appeal', [...$this->appealBody($receipt), 'tracking_token' => str_repeat('x',64)])->assertNotFound();
        $this->postJson('/api/v1/applications/appeal', [...$this->appealBody($receipt), 'confirmation' => false])->assertUnprocessable();
        $this->postJson('/api/v1/applications/appeal?tracking_token=prohibido', $this->appealBody($receipt))->assertUnprocessable();
        $this->assertDatabaseCount('cchia_admissions.appeals', 0);
    }

    public function test_appeal_retries_keep_one_case_and_never_replace_the_original_rejection(): void
    {
        $receipt = $this->rejected();
        for ($i=0; $i<2; $i++) $this->postJson('/api/v1/applications/appeal', $this->appealBody($receipt))->assertOk()->assertJsonPath('data.appeal.status','pending_assembly');
        $this->postJson('/api/v1/applications/appeal', [...$this->appealBody($receipt), 'reason' => 'Cambio silencioso'])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.applications', 1);
        $this->assertDatabaseCount('cchia_admissions.appeals', 1);
        $this->assertDatabaseCount('cchia_admissions.board_resolutions', 1);
        $this->assertDatabaseHas('cchia_admissions.applications', ['id' => $receipt['id'], 'status' => 'rejected']);
        $this->assertSame(1,DB::table('cchia_admissions.application_events')->where('action','application.appeal_requested')->count());
    }

    public function test_assembly_approval_is_separate_attributed_and_does_not_create_a_role(): void
    {
        $receipt = $this->rejected();
        $this->postJson('/api/v1/applications/appeal', $this->appealBody($receipt))->assertOk();
        $url = $this->url($receipt).'/appeal-resolution';
        $this->postJson($url,$this->assemblyBody())->assertOk()->assertJsonPath('data.effective_outcome','approved');
        $this->postJson($url,$this->assemblyBody())->assertOk();
        $this->postJson($url,$this->assemblyBody('rejected'))->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.assembly_resolutions',1);
        $this->assertDatabaseCount('public.users',3);
        $this->assertDatabaseCount('cchia_m02.role_assignments',3);
        $this->assertDatabaseHas('cchia_admissions.applications',['id'=>$receipt['id'],'status'=>'rejected']);
        $this->postJson('/api/v1/applications/track',$this->privateAccess($receipt))->assertOk()->assertJsonPath('data.casework.effective_outcome','approved')->assertJsonPath('data.decision.outcome','rejected')->assertDontSee('ACTA-FICTICIA-INTERNA')->assertDontSee('recorded_by_user_id');
    }

    public function test_confirmed_rejection_preserves_both_agreements_without_a_new_application(): void
    {
        $receipt=$this->rejected();
        $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $this->postJson($this->url($receipt).'/appeal-resolution',$this->assemblyBody('rejected'))->assertOk()->assertJsonPath('data.appeal.status','resolved')->assertJsonPath('data.effective_outcome','rejected');
        $this->postJson('/api/v1/applications/appeal',[...$this->appealBody($receipt),'reason'=>'Segunda apelación inventada'])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.applications',1);
        $this->assertDatabaseCount('cchia_admissions.board_resolutions',1);
        $this->assertDatabaseCount('cchia_admissions.assembly_resolutions',1);
    }

    public function test_future_assembly_and_missing_appeal_are_rejected(): void
    {
        $receipt=$this->rejected();
        $this->postJson($this->url($receipt).'/appeal-resolution',$this->assemblyBody())->assertConflict();
        $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $this->postJson($this->url($receipt).'/appeal-resolution',[...$this->assemblyBody(),'meeting_date'=>now('America/Santiago')->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson($this->url($receipt).'/appeal-resolution',[...$this->assemblyBody(),'meeting_date'=>now('America/Santiago')->subDay()->toDateString()])->assertUnprocessable();
        $this->assertDatabaseCount('cchia_admissions.assembly_resolutions',0);
    }

    public function test_correction_requires_explicit_permission_and_modal_documents(): void
    {
        $receipt=$this->submitted('juridica');
        DB::table('cchia_m02.role_permissions')->where('permission_code','admissions.corrections.manage')->delete();
        $this->postJson($this->url($receipt).'/corrections',['reason'=>'Legible','requested_kinds'=>['estatutos']],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        DB::table('cchia_m02.role_permissions')->insert(['role_code'=>'directorio','permission_code'=>'admissions.corrections.manage']);
        $this->postJson($this->url($receipt).'/corrections',['reason'=>'Legible','requested_kinds'=>['personeria']],['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->postJson($this->url($receipt).'/corrections',['reason'=>'Legible','requested_kinds'=>[['malformado']]],['Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('cchia_admissions.correction_requests',0);
    }

    public function test_request_retry_does_not_open_a_second_correction(): void
    {
        $receipt=$this->submitted('juridica'); $key=(string)Str::uuid();
        $body=['reason'=>'Adjuntar copia legible.','requested_kinds'=>['vigencia','estatutos']];
        for($i=0;$i<2;$i++) $this->postJson($this->url($receipt).'/corrections',$body,['Idempotency-Key'=>$key])->assertOk();
        $this->postJson($this->url($receipt).'/corrections',[...$body,'reason'=>'Cambiar'],['Idempotency-Key'=>$key])->assertConflict();
        $this->postJson($this->url($receipt).'/corrections',$body,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.correction_requests',1);
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertConflict();
    }

    public function test_corrected_documents_are_private_versions_with_originals_preserved(): void
    {
        $receipt=$this->submitted('juridica'); $originals=DB::table('cchia_admissions.application_documents')->orderBy('id')->get()->toJson();
        $id=$this->requestDocuments($receipt);
        $this->upload($receipt,$id)->assertOk()->assertJsonPath('data.corrections.0.status','submitted');
        $this->upload($receipt,$id)->assertOk();
        $this->upload($receipt,$id,'otro')->assertConflict();
        $this->assertSame($originals,DB::table('cchia_admissions.application_documents')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('cchia_admissions.document_revisions',1);
        $this->assertDatabaseHas('cchia_admissions.document_revisions',['application_id'=>$receipt['id'],'kind'=>'estatutos','version_no'=>2]);
        $this->assertCount(3,Storage::disk('admissions-private')->allFiles());
        $this->postJson('/api/v1/applications/track',$this->privateAccess($receipt))->assertOk()->assertDontSee('storage_key')->assertDontSee('request_hash')->assertDontSee('requested_by_user_id');
    }

    public function test_unrelated_case_cannot_upload_or_download_a_document_revision(): void
    {
        $receipt=$this->submitted('juridica'); $id=$this->requestDocuments($receipt); $this->upload($receipt,$id)->assertOk();
        $revision=DB::table('cchia_admissions.document_revisions')->first();
        $this->getJson($this->url($receipt).'/revisions/'.$revision->id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $intake=$this->create(data:[...$this->data(),'email'=>'otro-expediente@example.test'])->assertCreated()->json('data');
        $this->acceptBoth($intake);
        $other=$this->postJson('/api/v1/intakes/submit',[...$this->credentials($intake),'declaration_accepted'=>true])->assertCreated()->json('data');
        Sanctum::actingAs(User::query()->where('email','directorio@example.test')->firstOrFail());
        $this->getJson($this->url($other).'/revisions/'.$revision->id)->assertNotFound();
        $this->post('/api/v1/applications/corrections/submit',[...$this->privateAccess($other),'correction_id'=>$id,'confirmation'=>true,'documents'=>['estatutos'=>$this->pdf('otro')]],['Accept'=>'application/json'])->assertNotFound();
        Sanctum::actingAs(User::query()->where('email','socio1@example.test')->firstOrFail());
        $this->getJson($this->url($receipt).'/revisions/'.$revision->id)->assertForbidden();
        $this->post('/api/v1/applications/corrections/submit',[...$this->privateAccess($receipt),'tracking_token'=>str_repeat('x',64),'correction_id'=>$id,'confirmation'=>true,'documents'=>['estatutos'=>$this->pdf('falso')]],['Accept'=>'application/json'])->assertNotFound();
    }

    public function test_invalid_or_unrequested_files_are_rejected_without_writes(): void
    {
        $receipt=$this->submitted('juridica'); $id=$this->requestDocuments($receipt);
        $path=tempnam(sys_get_temp_dir(),'cchia-invalid-'); file_put_contents($path,'texto sin formato');
        try { foreach ([['vigencia'=>$this->pdf('otro')],['estatutos'=>new UploadedFile($path,'falso.pdf','application/pdf',null,true)],['estatutos'=>UploadedFile::fake()->create('grande.pdf',5121,'application/pdf')]] as $files) {
            $this->post('/api/v1/applications/corrections/submit',[...$this->privateAccess($receipt),'correction_id'=>$id,'confirmation'=>true,'documents'=>$files],['Accept'=>'application/json'])->assertUnprocessable();
        }
        } finally { @unlink($path); }
        $this->assertDatabaseCount('cchia_admissions.document_revisions',0);
        $this->assertDatabaseCount('cchia_admissions.correction_submissions',0);
        $this->assertCount(2,Storage::disk('admissions-private')->allFiles());
    }

    public function test_review_is_required_and_repeated_observations_keep_incrementing_versions(): void
    {
        $receipt=$this->submitted('juridica'); $first=$this->requestDocuments($receipt); $this->upload($receipt,$first)->assertOk();
        $body=['outcome'=>'needs_changes','reason'=>'Aún no es legible.','confirmation'=>true];
        $url=$this->url($receipt).'/corrections/'.$first.'/review';
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertConflict();
        for($i=0;$i<2;$i++) $this->postJson($url,$body)->assertOk();
        $this->postJson($url,[...$body,'outcome'=>'accepted'])->assertConflict();
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertConflict();
        $second=$this->requestDocuments($receipt); $this->upload($receipt,$second,'legible')->assertOk();
        $this->postJson($this->url($receipt).'/corrections/'.$second.'/review',['outcome'=>'accepted','reason'=>'Copia revisada y legible.','confirmation'=>true])->assertOk();
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertOk();
        $this->assertDatabaseCount('cchia_admissions.correction_requests',2);
        $this->assertDatabaseCount('cchia_admissions.correction_reviews',2);
        $this->assertDatabaseHas('cchia_admissions.document_revisions',['application_id'=>$receipt['id'],'version_no'=>3]);
    }

    public function test_correction_cannot_be_reviewed_before_submission_or_requested_after_decision(): void
    {
        $receipt=$this->submitted('juridica'); $id=$this->requestDocuments($receipt);
        $this->postJson($this->url($receipt).'/corrections/'.$id.'/review',['outcome'=>'accepted','reason'=>'Inválido','confirmation'=>true])->assertConflict();
        $this->upload($receipt,$id)->assertOk();
        $this->postJson($this->url($receipt).'/corrections/'.$id.'/review',['outcome'=>'accepted','reason'=>'Legible','confirmation'=>true])->assertOk();
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertOk();
        $this->postJson($this->url($receipt).'/corrections',['reason'=>'Tarde','requested_kinds'=>['estatutos']],['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
    }

    public function test_formalization_needs_approval_enablement_and_separate_permission(): void
    {
        $receipt=$this->submitted(); $url=$this->url($receipt).'/formalize';
        $body=['register_reference'=>'SOCIO-FICTICIO-001','effective_date'=>now('America/Santiago')->toDateString(),'confirmation'=>true];
        $this->postJson($url,$body)->assertConflict();
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertOk();
        config(['admissions.formalization_enabled'=>false]); $this->postJson($url,$body)->assertStatus(503);
        config(['admissions.formalization_enabled'=>true]);
        DB::table('cchia_m02.role_permissions')->where('permission_code','admissions.membership.formalize')->delete();
        $this->postJson($url,$body)->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.membership_registrations',0);
    }

    public function test_express_formalization_is_unique_and_does_not_grant_access_or_votes(): void
    {
        $receipt=$this->rejected(); $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $this->postJson($this->url($receipt).'/appeal-resolution',$this->assemblyBody())->assertOk();
        $body=['register_reference'=>'SOCIO-FICTICIO-001','effective_date'=>now('America/Santiago')->toDateString(),'confirmation'=>true];
        for($i=0;$i<2;$i++) $this->postJson($this->url($receipt).'/formalize',$body)->assertOk()->assertJsonPath('data.membership.register_reference','SOCIO-FICTICIO-001');
        $this->postJson($this->url($receipt).'/formalize',[...$body,'register_reference'=>'Cambio'])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.membership_registrations',1);
        $this->assertDatabaseCount('public.users',3); $this->assertDatabaseCount('cchia_m02.role_assignments',3);
        $this->postJson('/api/v1/applications/track',$this->privateAccess($receipt))->assertOk()->assertJsonPath('data.casework.membership.effective_date',$body['effective_date'])->assertDontSee('SOCIO-FICTICIO-001')->assertDontSee('identity_hash');
    }

    public function test_no_one_can_approve_or_formalize_their_own_application_even_with_all_permissions(): void
    {
        $receipt=$this->submitted();
        $reviewer=User::query()->where('email','directorio@example.test')->firstOrFail();
        $reviewer->update(['email'=>'postulante@example.test']); Sanctum::actingAs($reviewer);
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertForbidden();
        $this->postJson($this->url($receipt).'/formalize',['register_reference'=>'R','effective_date'=>now()->toDateString(),'confirmation'=>true])->assertForbidden();
        $this->postJson($this->url($receipt).'/corrections',['reason'=>'Propio','requested_kinds'=>['antecedentes']],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.board_resolutions',0);
    }

    public function test_expired_institutional_assignments_lose_all_new_powers(): void
    {
        $receipt=$this->rejected(); $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $user=User::query()->where('email','directorio@example.test')->firstOrFail();
        DB::table('cchia_m02.role_assignments')->where('user_id',$user->id)->update(['ends_at'=>now()]);
        $this->postJson($this->url($receipt).'/appeal-resolution',$this->assemblyBody())->assertForbidden();
        $this->getJson($this->url($receipt))->assertForbidden();
    }

    public function test_injection_payloads_do_not_expand_reads_or_execute_in_the_database(): void
    {
        $receipt=$this->submitted();
        $this->getJson('/api/v1/admin/applications?reference='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0,'data');
        $this->postJson('/api/v1/applications/track',[...$this->privateAccess($receipt),'reference'=>"' OR 1=1 --"])->assertUnprocessable();
        $id=$this->requestDocuments($receipt,['antecedentes']);
        $this->assertDatabaseCount('cchia_admissions.applications',1);
        $this->assertDatabaseHas('cchia_admissions.correction_requests',['id'=>$id]);
    }

    public function test_event_notifications_are_deduplicated_and_do_not_leak_credentials_or_reasons(): void
    {
        Mail::fake(); $receipt=$this->rejected();
        for($i=0;$i<2;$i++) $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $this->assertSame(2,DB::table('cchia_admissions.notification_outbox')->where('kind','application_event')->count());
        app(NotificationOutbox::class)->process(); Mail::assertSent(AdmissionUpdate::class,2);
        $text=(new AdmissionUpdate($receipt['reference'],'application.decision_recorded'))->render();
        $this->assertStringNotContainsString($receipt['tracking_token'],$text);
        $this->assertStringNotContainsString($this->agreement()['reason'],$text);
        $this->assertStringContainsString('/seguimiento',$text);
    }

    public function test_queue_filters_follow_corrections_appeal_and_registration(): void
    {
        $receipt=$this->submitted('juridica'); $id=$this->requestDocuments($receipt);
        $this->getJson('/api/v1/admin/applications?task=waiting_applicant')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.workflow','waiting_applicant');
        $this->getJson('/api/v1/admin/applications?task=documents_submitted')->assertOk()->assertJsonCount(0,'data');
        $this->upload($receipt,$id)->assertOk();
        $this->getJson('/api/v1/admin/applications?task=waiting_applicant')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/applications?task=documents_submitted')->assertOk()->assertJsonCount(1,'data');
        $this->postJson($this->url($receipt).'/corrections/'.$id.'/review',['outcome'=>'accepted','reason'=>'Legible','confirmation'=>true])->assertOk();
        $this->postJson($this->url($receipt).'/decision',$this->agreement('rejected'))->assertOk();
        $this->postJson('/api/v1/applications/appeal',$this->appealBody($receipt))->assertOk();
        $this->getJson('/api/v1/admin/applications?task=pending_assembly')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.workflow','pending_assembly');
        $this->postJson($this->url($receipt).'/appeal-resolution',$this->assemblyBody())->assertOk();
        $this->getJson('/api/v1/admin/applications?task=pending_assembly')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/applications?task=approved_unregistered')->assertOk()->assertJsonCount(1,'data');
        $this->postJson($this->url($receipt).'/formalize',['register_reference'=>'QA-REGISTRO','effective_date'=>now('America/Santiago')->toDateString(),'confirmation'=>true])->assertOk();
        $this->getJson('/api/v1/admin/applications?task=approved_unregistered')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/applications?task=inventado')->assertUnprocessable();
    }

    public function test_failed_revision_storage_preserves_originals_and_does_not_record_submission(): void
    {
        $receipt=$this->submitted('juridica'); $id=$this->requestDocuments($receipt);
        $disk=Storage::disk('admissions-private'); $proxy=\Mockery::mock($disk);
        $proxy->shouldReceive('putFileAs')->once()->andThrow(new \RuntimeException('Fallo ficticio de almacenamiento.'));
        Storage::shouldReceive('disk')->with('admissions-private')->andReturn($proxy);
        $this->upload($receipt,$id)->assertStatus(500);
        $this->assertDatabaseCount('cchia_admissions.correction_submissions',0);
        $this->assertDatabaseCount('cchia_admissions.document_revisions',0);
        $this->assertCount(2,$disk->allFiles());
        $this->assertSame(0,DB::table('cchia_admissions.application_events')->where('action','application.correction_submitted')->count());
    }

    public function test_two_approved_cases_with_the_same_identity_cannot_be_registered_twice(): void
    {
        $first=$this->submitted();
        $reviewer=User::query()->where('email','directorio@example.test')->firstOrFail();
        $this->postJson($this->url($first).'/decision',$this->agreement())->assertOk();
        $body=['register_reference'=>'QA-UNICO-1','effective_date'=>now('America/Santiago')->toDateString(),'confirmation'=>true];
        $this->postJson($this->url($first).'/formalize',$body)->assertOk();
        $intake=$this->create(data:[...$this->data(),'email'=>'otro-postulante@example.test','identifier'=>'identidad.privada'])->assertCreated()->json('data');
        $this->acceptBoth($intake);
        $second=$this->postJson('/api/v1/intakes/submit',[...$this->credentials($intake),'declaration_accepted'=>true])->assertCreated()->json('data');
        Sanctum::actingAs($reviewer);
        $this->postJson($this->url($second).'/start-review')->assertOk();
        $this->postJson($this->url($second).'/decision',$this->agreement())->assertOk();
        $this->postJson($this->url($second).'/formalize',[...$body,'register_reference'=>'QA-UNICO-2'])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.membership_registrations',1);
    }

    public function test_release_check_reports_blockers_and_never_prints_credentials(): void
    {
        $checks=app(\App\Services\ReleaseReadiness::class)->checks();
        $this->assertFalse($checks['environment_production']);
        $this->assertFalse($checks['real_mail_transport']);
        $this->assertFalse($checks['https_same_origin']);
        $this->assertTrue($checks['table_appeals']);
        $this->artisan('admissions:release-check')->expectsOutputToContain('PENDIENTE')->assertExitCode(1);
    }

    public function test_local_permission_preparation_is_refused_in_production(): void
    {
        $this->app->instance('env','production');
        $this->artisan('admissions:local-review-permissions directorio@example.test')->expectsOutputToContain('Solo se permite')->assertExitCode(1);
        $this->assertSame(0,DB::table('cchia_m02.role_permissions')->where('permission_code','admissions.appeals.record')->count());
    }

    public function test_admitted_extensions_must_match_the_detected_document_type(): void
    {
        $intake=$this->ready('juridica');
        $path=tempnam(sys_get_temp_dir(),'cchia-extension-');
        file_put_contents($path,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        try {
            $this->post('/api/v1/intakes/submit',[...$this->credentials($intake),'declaration_accepted'=>true,'documents'=>['estatutos'=>new UploadedFile($path,'pdf-disfrazado.jpg','image/jpeg',null,true),'vigencia'=>$this->pdf('vigencia')]],['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('documents.estatutos');
            $this->assertDatabaseCount('cchia_admissions.applications',0);
            $receipt=$this->post('/api/v1/intakes/submit',[...$this->credentials($intake),'declaration_accepted'=>true,'documents'=>['estatutos'=>$this->pdf('estatutos'),'vigencia'=>$this->pdf('vigencia')]],['Accept'=>'application/json'])->assertCreated()->json('data');
            $this->reviewer(); $this->postJson($this->url($receipt).'/start-review')->assertOk();
            $id=$this->requestDocuments($receipt);
            $this->post('/api/v1/applications/corrections/submit',[...$this->privateAccess($receipt),'correction_id'=>$id,'confirmation'=>true,'documents'=>['estatutos'=>new UploadedFile($path,'pdf-disfrazado.png','image/png',null,true)]],['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('documents.estatutos');
            $this->assertDatabaseCount('cchia_admissions.document_revisions',0);
            $this->assertCount(2,Storage::disk('admissions-private')->allFiles());
        } finally { @unlink($path); }
    }
}
