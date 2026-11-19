<?php

namespace Tests\Feature;

use App\Http\Controllers\MembershipRegisterController;
use App\Models\User;
use App\Services\CchiaOperations;
use App\Services\NotificationOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdmissionWorkflowFixtures;
use Tests\TestCase;

class OperationsAdministrationTest extends TestCase
{
    use DatabaseTransactions, AdmissionWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutMiddleware(ThrottleRequests::class); $this->freezeTime();
        config(['admissions.require_member_sponsors'=>true,'admissions.sponsorships_enabled'=>true,'admissions.formalization_enabled'=>true]);
        Storage::fake('admissions-private');
    }

    public function test_register_read_and_export_are_separate_permissions(): void
    {
        $r=$this->registered('juridica');
        $this->getJson('/api/v1/admin/members')->assertForbidden();
        DB::table('cchia_m02.role_permissions')->insert(['role_code'=>'directorio','permission_code'=>'admissions.membership.read']);
        $this->getJson('/api/v1/admin/members')->assertOk()->assertJsonCount(1,'data')->assertJsonMissingPath('data.0.email')->assertDontSee('tracking_token_hash')->assertDontSee('identity_hash');
        $this->getJson('/api/v1/admin/members/'.$r['registration_id'])->assertOk()->assertJsonPath('data.representative_identifier','RUT-REPRESENTANTE')->assertJsonPath('data.organization_identifier','RUT-ORGANIZACION');
        $this->getJson('/api/v1/admin/members/export')->assertForbidden();
        DB::table('cchia_m02.role_permissions')->insert(['role_code'=>'directorio','permission_code'=>'admissions.membership.export']);
        $response=$this->get('/api/v1/admin/members/export')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $csv=$response->streamedContent();
        $this->assertStringContainsString('RUT-REPRESENTANTE',$csv);
        $this->assertStringNotContainsString($r['tracking_token'],$csv);
        $this->assertSame(1,DB::table('cchia_m02.authorization_audit_log')->where('action','admissions.membership.export_started')->count());
    }

    public function test_register_does_not_turn_unformalized_approvals_into_members(): void
    {
        $r=$this->submitted(); $this->operationsPermissions();
        $this->postJson($this->url($r).'/decision',$this->agreement())->assertOk();
        $this->getJson('/api/v1/admin/members')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/members/'.$r['id'])->assertNotFound();
    }

    public function test_member_role_or_expired_director_cannot_read_the_private_register(): void
    {
        $r=$this->registered(); $this->operationsPermissions();
        $user=User::query()->where('email','directorio@example.test')->firstOrFail();
        DB::table('cchia_m02.role_assignments')->where('user_id',$user->id)->update(['ends_at'=>now()]);
        $this->getJson('/api/v1/admin/members/'.$r['registration_id'])->assertForbidden();
        Sanctum::actingAs(User::query()->where('email','socio1@example.test')->firstOrFail());
        $this->getJson('/api/v1/admin/members')->assertForbidden();
        $this->getJson('/api/v1/admin/members/export')->assertForbidden();
    }

    public function test_register_search_escapes_wildcards_and_uses_bound_values(): void
    {
        $this->registered(); $this->operationsPermissions();
        $this->getJson('/api/v1/admin/members?q=Ficticia')->assertOk()->assertJsonCount(1,'data');
        foreach(['%',"' OR 1=1 --",'_'] as $q) $this->getJson('/api/v1/admin/members?q='.urlencode($q))->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/members?legal=juridica')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/admin/members?page=-1')->assertUnprocessable();
    }

    public function test_csv_cells_cannot_execute_spreadsheet_formulas(): void
    {
        foreach(['=1+1','+CMD','-1+2','@SUM(A1)'," \t=1+1","\ttexto","\n=1+1"] as $value) $this->assertStringStartsWith("'",MembershipRegisterController::csvCell($value));
        $this->assertSame('Persona',MembershipRegisterController::csvCell('Persona'));
        $this->assertSame('12345678-9',MembershipRegisterController::csvCell('12345678-9'));
    }

    public function test_notification_list_excludes_recipients_tokens_and_error_details(): void
    {
        $r=$this->submitted(); $this->operationsPermissions(); $this->exhausted($r);
        $this->getJson('/api/v1/admin/notifications?status=failed')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.can_retry',true)
            ->assertDontSee('postulante@example.test')->assertDontSee($r['tracking_token'])->assertDontSee('RuntimeException')->assertDontSee('application_id');
    }

    public function test_only_an_exhausted_error_can_be_reactivated_and_retries_are_idempotent(): void
    {
        $r=$this->submitted(); $this->operationsPermissions(); $id=$this->exhausted($r); $key=(string)Str::uuid();
        $body=['reason'=>'Transporte revisado y corregido para esta prueba.','confirmation'=>true];
        $url='/api/v1/admin/notifications/'.$id.'/retry';
        $this->postJson($url,[...$body,'confirmation'=>false],['Idempotency-Key'=>$key])->assertUnprocessable();
        $first=$this->postJson($url,$body,['Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.replayed',false);
        $this->postJson($url,$body,['Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.retry_id',$first->json('data.retry_id'));
        $this->postJson($url,[...$body,'reason'=>'Modificar el motivo silenciosamente.'],['Idempotency-Key'=>$key])->assertConflict();
        $this->postJson($url,$body,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.notification_retries',1);
        $this->assertDatabaseHas('cchia_admissions.notification_outbox',['id'=>$id,'attempts'=>0,'abandoned_at'=>null]);
        $this->assertDatabaseHas('cchia_admissions.notification_retries',['notification_id'=>$id,'previous_attempts'=>5,'previous_error_type'=>'RuntimeException']);
    }

    public function test_reactivation_after_transport_processing_does_not_schedule_a_second_send(): void
    {
        Mail::fake(); $r=$this->submitted(); $this->operationsPermissions(); $id=$this->exhausted($r);
        $key=(string)Str::uuid(); $url='/api/v1/admin/notifications/'.$id.'/retry'; $body=['reason'=>'Error del transporte resuelto en entorno ficticio.','confirmation'=>true];
        $this->postJson($url,$body,['Idempotency-Key'=>$key])->assertOk();
        app(NotificationOutbox::class)->process();
        $this->postJson($url,$body,['Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.replayed',true);
        $this->assertNotNull(DB::table('cchia_admissions.notification_outbox')->where('id',$id)->value('sent_at'));
        $this->postJson($url,$body,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
    }

    public function test_pending_or_intentionally_omitted_notifications_are_not_reactivated(): void
    {
        $r=$this->submitted(); $this->operationsPermissions();
        $id=DB::table('cchia_admissions.notification_outbox')->where('application_id',$r['id'])->value('id');
        $body=['reason'=>'No debe reactivarse un pendiente.','confirmation'=>true];
        $this->postJson('/api/v1/admin/notifications/'.$id.'/retry',$body,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        DB::table('cchia_admissions.notification_outbox')->where('id',$id)->update(['attempts'=>1,'abandoned_at'=>now()]);
        $this->postJson('/api/v1/admin/notifications/'.$id.'/retry',$body,['Idempotency-Key'=>(string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('cchia_admissions.notification_retries',0);
    }

    public function test_existing_read_permission_does_not_allow_notification_administration(): void
    {
        $r=$this->submitted(); $id=$this->exhausted($r);
        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
        $this->postJson('/api/v1/admin/notifications/'.$id.'/retry',['reason'=>'Sin permiso no procede.','confirmation'=>true],['Idempotency-Key'=>(string)Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('cchia_admissions.notification_retries',0);
    }

    public function test_file_integrity_checks_read_originals_and_detect_modification_without_exposing_paths(): void
    {
        $r=$this->submitted('juridica');
        $service=app(CchiaOperations::class); $good=$service->files();
        $this->assertTrue($good['ok']); $this->assertSame(2,$good['checked']);
        $row=DB::table('cchia_admissions.application_documents')->first(); Storage::disk('admissions-private')->put($row->storage_key,'alterado');
        $bad=$service->files(); $this->assertFalse($bad['ok']); $this->assertSame('integrity_mismatch',$bad['issues'][0]['reason']);
        $this->assertStringNotContainsString($row->storage_key,json_encode($bad));
        $this->assertStringNotContainsString($r['tracking_token'],json_encode($bad));
    }

    public function test_bounded_file_checks_never_claim_full_integrity(): void
    {
        $this->submitted('juridica'); $result=app(CchiaOperations::class)->files(1);
        $this->assertFalse($result['ok']); $this->assertFalse($result['complete']); $this->assertSame(1,$result['checked']);
        $this->assertTrue(app(CchiaOperations::class)->files(1,true)['ok']);
    }

    public function test_missing_document_is_reported_without_modifying_its_record(): void
    {
        $this->submitted('juridica'); $row=DB::table('cchia_admissions.application_documents')->first();
        Storage::disk('admissions-private')->delete($row->storage_key);
        $report=app(CchiaOperations::class)->files();
        $this->assertFalse($report['ok']); $this->assertSame('unreadable_or_missing',$report['issues'][0]['reason']);
        $this->assertDatabaseHas('cchia_admissions.application_documents',['id'=>$row->id,'sha256'=>$row->sha256]);
    }

    public function test_scheduler_heartbeat_expires_and_does_not_certify_mail_delivery(): void
    {
        Cache::forget('cchia:operations:scheduler:heartbeat'); $service=app(CchiaOperations::class);
        $this->assertFalse($service->heartbeat()['ok']); $service->pulse(); $this->assertTrue($service->heartbeat()['ok']);
        $this->travel(181)->seconds(); $this->assertFalse($service->heartbeat()['ok']);
        $mail=$service->mail(); $this->assertFalse($mail['real_transport']);
    }

    public function test_ops_command_is_discovered_and_rejects_invalid_arguments(): void
    {
        $this->artisan('cchia:ops permissions --json')->assertSuccessful();
        $this->artisan('cchia:ops files --limit=0')->assertExitCode(1);
        $this->artisan('cchia:ops heartbeat-status --max-age=5')->assertExitCode(1);
        $this->artisan('cchia:ops accion-inventada')->assertExitCode(1);
    }
}
