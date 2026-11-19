<?php

namespace Tests\Feature;

use App\Mail\WorkspaceInvitation;
use App\Models\User;
use App\Services\WorkspaceMailOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

class WorkspaceTest extends TestCase
{
    use DatabaseTransactions;
    private User $director;
    protected function setUp(): void
    {
        parent::setUp();$this->withoutMiddleware(ThrottleRequests::class);$this->freezeTime();
        config(['workspace.enabled'=>true]);Storage::fake('workspace-private');Mail::fake();
        DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code'=>'directorio','permission_code'=>'workspace.commissions.manage']);
        $this->director=$this->account('directorio');
    }
    private function account(?string $role=null,?string $scope=null,?string $email=null): User
    {
        $u=User::query()->create(['name'=>'Persona Ficticia','email'=>$email??Str::uuid().'@example.test','password'=>'ClaveFicticia-123456']);
        if($role!==null){
            $scope??=DB::table('cchia_m02.access_scopes')->where('kind','global')->value('id');
            DB::table('cchia_m02.role_assignments')->insert(['id'=>(string)Str::uuid(),'user_id'=>$u->id,'role_code'=>$role,'scope_id'=>$scope,
                'status'=>'active','accepted_at'=>now()->subDay(),'starts_at'=>now()->subDay()]);
        }
        return $u;
    }
    private function signIn(User $u): void{$this->flushSession();$this->actingAs($u,'web');}
    private function postAs(User $u,string $url,array $data,?string $key=null)
    {
        $this->signIn($u);return $this->postJson($url,$data,['Idempotency-Key'=>$key??(string)Str::uuid()]);
    }
    private function commission(string $name='Comisión Ficticia'): array
    {
        $id=$this->postAs($this->director,'/api/v1/workspace/commissions',['name'=>$name,'description'=>'Trabajo ficticio de pruebas.'])->assertOk()->json('data.id');
        return ['id'=>$id,'scope'=>DB::table('cchia_workspace.commissions')->where('commission_id',$id)->value('scope_id')];
    }
    private function url(array $c,string $tail=''): string{return '/api/v1/workspace/commissions/'.$c['id'].$tail;}
    private function leader(array $c): User{return $this->account('lider_comision',$c['scope']);}
    private function member(array $c): User{return $this->account('miembro_comision',$c['scope']);}
    private function project(array $c,?User $u=null): string
    {
        return $this->postAs($u??$this->director,$this->url($c,'/projects'),['name'=>'Proyecto Ficticio','description'=>'Descripción ficticia.'])->assertOk()->json('data.id');
    }
    private function task(array $c,string $project,?User $assigned=null): string
    {
        return $this->postAs($this->director,$this->url($c,'/projects/'.$project.'/tasks'),['title'=>'Tarea ficticia','description'=>'Trabajo de prueba.','assignee_user_id'=>$assigned?->id])->assertOk()->json('data.id');
    }
    private function invitation(array $c,string $email,?User $actor=null,string $role='miembro_comision',?string $end=null): array
    {
        $id=$this->postAs($actor??$this->director,$this->url($c,'/invitations'),['email'=>$email,'role_code'=>$role,'ends_at'=>$end,
            'appointment_reference'=>$role==='lider_comision'?'ACTA-FICTICIA-LIDER':null,'confirmation'=>true])->assertOk()->json('data.id');
        return ['invitation_id'=>$id,'code'=>Crypt::decryptString(DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$id)->value('token_encrypted'))];
    }
    private function guest(): void{Auth::guard('web')->logout();$this->app['auth']->forgetGuards();$this->flushSession();}
    private function accept(array $invite,array $extra=[])
    {
        return $this->postJson('/api/v1/workspace/invitations/accept',[...$invite,'confirmation'=>true,'create_account'=>true,'name'=>'Nueva Persona Ficticia',
            'password'=>'AccesoFicticio-12345','password_confirmation'=>'AccesoFicticio-12345',...$extra]);
    }
    private function pdf(string $name='recurso.pdf'): UploadedFile{return UploadedFile::fake()->createWithContent($name,"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");}

    public function test_guests_cannot_read_profiles_or_commissions(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();$this->getJson('/api/v1/workspace/commissions')->assertUnauthorized();
    }
    public function test_profile_starts_private_and_does_not_expose_security_fields(): void
    {
        $u=$this->account();$this->signIn($u);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.version',0)->assertJsonPath('data.share_biography',false)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    }
    public function test_profile_updates_own_display_data_and_rejects_stale_versions(): void
    {
        $u=$this->account();$data=['name'=>'Nombre de cuenta actualizado','biography'=>'Actividad ficticia','share_biography'=>false,'version'=>0];
        $this->postAs($u,'/api/v1/profile',$data)->assertOk()->assertJsonPath('data.version',1);
        $this->postAs($u,'/api/v1/profile',[...$data,'name'=>'Cambio desde otra pestaña'])->assertConflict();
        $this->assertSame('Nombre de cuenta actualizado',$u->fresh()->name);
        $this->assertSame(1,DB::table('cchia_workspace.events')->where('action','profile.updated')->count());
    }
    public function test_profile_does_not_change_email_or_assign_roles(): void
    {
        $u=$this->account();$data=['name'=>'Nombre','biography'=>'','share_biography'=>false,'version'=>0,'email'=>'otra@example.test','role'=>'directorio'];
        $this->postAs($u,'/api/v1/profile',$data)->assertUnprocessable();$this->assertSame(0,DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->count());
    }
    public function test_wrong_password_does_not_change_account_and_malformed_values_are_validation_errors(): void
    {
        $u=$this->account();$body=['current_password'=>'Incorrecta','password'=>'NuevaClave-123456','password_confirmation'=>'NuevaClave-123456'];
        $this->postAs($u,'/api/v1/profile/password',$body)->assertUnprocessable();
        $this->postAs($u,'/api/v1/profile/password',[...$body,'password'=>['invalid']])->assertUnprocessable();
        $this->assertTrue(Hash::check('ClaveFicticia-123456',$u->fresh()->password));
    }
    public function test_changing_password_revokes_tokens_and_signs_out_current_session(): void
    {
        $u=$this->account();$u->createToken('prueba');
        $this->postAs($u,'/api/v1/profile/password',['current_password'=>'ClaveFicticia-123456','password'=>'NuevaClave-123456','password_confirmation'=>'NuevaClave-123456'])
            ->assertOk()->assertJsonPath('data.signed_out',true);
        $this->assertTrue(Hash::check('NuevaClave-123456',$u->fresh()->password));$this->assertSame(0,$u->tokens()->count());
        $this->getJson('/api/v1/session/me')->assertUnauthorized();
    }
    public function test_technical_administrator_cannot_create_commissions_even_with_catalog_permission(): void
    {
        $u=$this->account('administrador_plataforma');DB::table('cchia_m02.role_permissions')->insert(['role_code'=>'administrador_plataforma','permission_code'=>'workspace.commissions.manage']);
        $this->postAs($u,'/api/v1/workspace/commissions',['name'=>'No autorizada','description'=>''])->assertForbidden();
    }
    public function test_disabled_workspace_does_not_disable_own_profile(): void
    {
        config(['workspace.enabled'=>false]);$this->signIn($this->director);
        $this->getJson('/api/v1/workspace/commissions')->assertStatus(503);$this->getJson('/api/v1/profile')->assertOk();
    }
    public function test_commission_creation_is_idempotent_and_changed_payload_cannot_reuse_key(): void
    {
        $key=(string)Str::uuid();$body=['name'=>'Comisión Repetida','description'=>'Prueba'];
        $id=$this->postAs($this->director,'/api/v1/workspace/commissions',$body,$key)->assertOk()->json('data.id');
        $this->postAs($this->director,'/api/v1/workspace/commissions',$body,$key)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.id',$id);
        $this->postAs($this->director,'/api/v1/workspace/commissions',[...$body,'name'=>'Otra'],$key)->assertConflict();
        $this->assertSame(1,DB::table('cchia_workspace.commissions')->count());
    }
    public function test_duplicate_commission_names_are_rejected_without_partial_scopes(): void
    {
        $this->commission();$this->postAs($this->director,'/api/v1/workspace/commissions',['name'=>'comisión ficticia','description'=>''])->assertUnprocessable();
        $this->assertSame(1,DB::table('cchia_m02.access_scopes')->where('kind','commission')->count());
    }
    public function test_members_only_see_their_commission_and_no_private_profile_or_email(): void
    {
        $a=$this->commission();$b=$this->commission('Comisión Oculta');$u=$this->member($a);$other=$this->member($a);
        $this->postAs($other,'/api/v1/profile',['name'=>'Persona de la comisión','biography'=>'Biografía privada','share_biography'=>false,'version'=>0])->assertOk();
        $this->signIn($u);$this->getJson('/api/v1/workspace/commissions')->assertOk()->assertJsonCount(1,'data')->assertDontSee('Comisión Oculta');
        $this->getJson($this->url($a))->assertOk()->assertDontSee('Biografía privada')->assertDontSee($other->email);
        $this->getJson($this->url($b))->assertNotFound();
    }
    public function test_biography_is_shared_only_after_explicit_choice(): void
    {
        $c=$this->commission();$u=$this->member($c);$other=$this->member($c);
        $this->postAs($other,'/api/v1/profile',['name'=>'Persona Visible','biography'=>'Biografía compartida','share_biography'=>true,'version'=>0])->assertOk();
        $this->signIn($u);$this->getJson($this->url($c))->assertOk()->assertJsonFragment(['biography'=>'Biografía compartida'])->assertDontSee($other->email);
    }
    public function test_expired_or_revoked_assignments_cannot_read_commission(): void
    {
        $c=$this->commission();$u=$this->member($c);$this->signIn($u);
        DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->update(['ends_at'=>now()]);$this->getJson($this->url($c))->assertNotFound();
        DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->update(['ends_at'=>null,'revoked_at'=>now()]);$this->getJson($this->url($c))->assertNotFound();
    }
    public function test_members_cannot_create_projects_or_invite_others(): void
    {
        $c=$this->commission();$u=$this->member($c);
        $this->postAs($u,$this->url($c,'/projects'),['name'=>'No autorizado','description'=>''])->assertForbidden();
        $this->postAs($u,$this->url($c,'/invitations'),['email'=>'otra@example.test','role_code'=>'miembro_comision','appointment_reference'=>null,'confirmation'=>true])->assertForbidden();
    }
    public function test_project_versions_and_parent_scope_are_enforced(): void
    {
        $a=$this->commission();$b=$this->commission('Comisión B');$leader=$this->leader($a);$p=$this->project($a,$leader);
        $body=['name'=>'Proyecto Renombrado','description'=>'Actualizado','status'=>'active','version'=>1];
        $this->postAs($leader,$this->url($a,'/projects/'.$p),$body)->assertOk()->assertJsonPath('data.version',2);
        $this->postAs($leader,$this->url($a,'/projects/'.$p),$body)->assertConflict();
        $this->postAs($this->director,$this->url($b,'/projects/'.$p),$body)->assertNotFound();
    }
    public function test_task_cannot_be_assigned_to_account_outside_commission(): void
    {
        $c=$this->commission();$p=$this->project($c);$outsider=$this->account('socio');
        $this->postAs($this->director,$this->url($c,'/projects/'.$p.'/tasks'),['title'=>'Tarea','description'=>'','assignee_user_id'=>$outsider->id])->assertUnprocessable();
        $this->assertSame(0,DB::table('cchia_workspace.tasks')->count());
    }
    public function test_assignee_can_only_change_status_of_their_own_task(): void
    {
        $c=$this->commission();$u=$this->member($c);$other=$this->member($c);$p=$this->project($c);$t=$this->task($c,$p,$u);$url=$this->url($c,'/projects/'.$p.'/tasks/'.$t);
        $this->postAs($other,$url,['status'=>'done','version'=>1])->assertForbidden();
        $this->postAs($u,$url,['title'=>'Cambio no permitido','status'=>'done','version'=>1])->assertForbidden();
        $this->postAs($u,$url,['status'=>'in_progress','version'=>1])->assertOk()->assertJsonPath('data.version',2);
        $this->postAs($u,$url,['status'=>'done','version'=>1])->assertConflict();
    }
    public function test_archived_projects_are_readable_but_reject_task_writes(): void
    {
        $c=$this->commission();$p=$this->project($c);
        $this->postAs($this->director,$this->url($c,'/projects/'.$p),['name'=>'Proyecto Ficticio','description'=>'','status'=>'archived','version'=>1])->assertOk();
        $this->postAs($this->director,$this->url($c,'/projects/'.$p.'/tasks'),['title'=>'No permitida','description'=>''])->assertConflict();
        $this->getJson($this->url($c,'/projects/'.$p.'/tasks'))->assertOk();
    }
    public function test_archived_commissions_keep_read_history_and_can_be_reopened_by_director(): void
    {
        $c=$this->commission();$body=['name'=>'Comisión Ficticia','description'=>'','status'=>'archived','version'=>1];
        $this->postAs($this->director,$this->url($c),$body)->assertOk();$this->getJson($this->url($c))->assertOk()->assertJsonPath('data.can_manage',false);
        $this->postAs($this->director,$this->url($c,'/projects'),['name'=>'No permitido','description'=>''])->assertConflict();
        $this->postAs($this->director,$this->url($c),[...$body,'status'=>'active','version'=>2])->assertOk();
    }
    public function test_private_resource_upload_is_idempotent_and_cannot_be_downloaded_under_another_scope(): void
    {
        $a=$this->commission();$b=$this->commission('Comisión B');$u=$this->member($a);$key=(string)Str::uuid();
        $this->signIn($this->director);$url=$this->url($a,'/resources');
        $id=$this->post($url,['title'=>'Documento ficticio','file'=>$this->pdf()],['Accept'=>'application/json','Idempotency-Key'=>$key])->assertOk()->json('data.id');
        $this->post($url,['title'=>'Documento ficticio','file'=>$this->pdf()],['Accept'=>'application/json','Idempotency-Key'=>$key])->assertOk()->assertJsonPath('data.replayed',true);
        $this->assertCount(1,Storage::disk('workspace-private')->allFiles());$this->assertSame(1,DB::table('cchia_workspace.resources')->count());
        $this->signIn($u);$this->getJson($url)->assertOk()->assertJsonMissingPath('data.0.storage_key')->assertJsonMissingPath('data.0.sha256');
        $download=$this->get($this->url($a,'/resources/'.$id))->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringContainsString('no-store',$download->headers->get('Cache-Control'));
        $this->signIn($this->director);$this->getJson($this->url($b,'/resources/'.$id))->assertNotFound();
    }
    public function test_fake_extension_and_large_resource_files_are_rejected_without_storage(): void
    {
        $c=$this->commission();$this->signIn($this->director);
        $this->post($this->url($c,'/resources'),['title'=>'Prueba','file'=>UploadedFile::fake()->createWithContent('imagen.png',"%PDF-1.4\n%%EOF")],['Accept'=>'application/json','Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->post($this->url($c,'/resources'),['title'=>'Prueba','file'=>UploadedFile::fake()->create('grande.pdf',5121,'application/pdf')],['Accept'=>'application/json','Idempotency-Key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->assertCount(0,Storage::disk('workspace-private')->allFiles());
    }
    public function test_revocation_removes_scope_access_but_preserves_global_member_role(): void
    {
        $c=$this->commission();$u=$this->member($c);$global=DB::table('cchia_m02.access_scopes')->where('kind','global')->value('id');
        DB::table('cchia_m02.role_assignments')->insert(['user_id'=>$u->id,'role_code'=>'socio','scope_id'=>$global,'status'=>'active','starts_at'=>now()->subDay()]);
        $assignment=DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->where('scope_id',$c['scope'])->value('id');
        $this->postAs($this->leader($c),$this->url($c,'/members/'.$assignment.'/revoke'),['reason'=>'Finalizó la participación ficticia.','confirmation'=>true])->assertOk();
        $this->signIn($u);$this->getJson($this->url($c))->assertNotFound();
        $this->assertSame('active',DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->where('role_code','socio')->value('status'));
    }
    public function test_leader_cannot_revoke_another_leader(): void
    {
        $c=$this->commission();$one=$this->leader($c);$two=$this->leader($c);$id=DB::table('cchia_m02.role_assignments')->where('user_id',$two->id)->value('id');
        $this->postAs($one,$this->url($c,'/members/'.$id.'/revoke'),['reason'=>'Intento no autorizado.','confirmation'=>true])->assertForbidden();
        $this->postAs($this->director,$this->url($c,'/members/'.$id.'/revoke'),['reason'=>'Designación ficticia terminada.','confirmation'=>true])->assertOk();
    }
    public function test_search_wildcards_and_injection_do_not_expand_reads(): void
    {
        $this->commission();$this->signIn($this->director);
        foreach(['%',"' OR 1=1 --",'_'] as $q)$this->getJson('/api/v1/workspace/commissions?q='.urlencode($q))->assertOk()->assertJsonCount(0,'data');
    }
    public function test_invitation_issues_no_role_or_account_and_never_returns_private_code(): void
    {
        $c=$this->commission();$email='invitado@example.test';$before=User::query()->count();
        $res=$this->postAs($this->director,$this->url($c,'/invitations'),['email'=>$email,'role_code'=>'miembro_comision','appointment_reference'=>null,'confirmation'=>true])->assertOk();
        $id=$res->json('data.id');$code=Crypt::decryptString(DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$id)->value('token_encrypted'));
        $res->assertDontSee($code)->assertJsonMissingPath('data.token_hash');$this->assertSame($before,User::query()->count());
        $this->assertNotSame($code,DB::table('cchia_m02.role_invitations')->where('id',$id)->value('token_hash'));
        $this->getJson($this->url($c,'/invitations'))->assertOk()->assertDontSee($code)->assertJsonMissingPath('data.0.token_hash');
    }
    public function test_leader_only_invites_member_in_their_own_scope(): void
    {
        $a=$this->commission();$b=$this->commission('Comisión B');$leader=$this->leader($a);
        $body=['email'=>'persona@example.test','role_code'=>'lider_comision','appointment_reference'=>'ACTA-PRUEBA','confirmation'=>true];
        $this->postAs($leader,$this->url($a,'/invitations'),$body)->assertForbidden();
        $this->postAs($leader,$this->url($b,'/invitations'),[...$body,'role_code'=>'miembro_comision'])->assertNotFound();
        $this->postAs($this->director,$this->url($a,'/invitations'),[...$body,'role_code'=>'directorio'])->assertUnprocessable();
        $this->invitation($a,'persona@example.test',$leader);
    }
    public function test_self_invitation_and_duplicate_pending_invites_are_rejected(): void
    {
        $c=$this->commission();$body=['email'=>$this->director->email,'role_code'=>'miembro_comision','appointment_reference'=>null,'confirmation'=>true];
        $this->postAs($this->director,$this->url($c,'/invitations'),$body)->assertUnprocessable();
        $this->invitation($c,'persona@example.test');$this->postAs($this->director,$this->url($c,'/invitations'),[...$body,'email'=>'PERSONA@example.test'])->assertUnprocessable();
    }
    public function test_new_account_accepts_private_scoped_invitation_without_becoming_socio(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();
        $this->postJson('/api/v1/workspace/invitations/preview',$invite)->assertOk()->assertJsonPath('data.commission_name','Comisión Ficticia');
        $this->accept($invite)->assertOk()->assertJsonPath('data.replayed',false);
        $u=User::query()->where('email','nueva@example.test')->firstOrFail();$this->assertNotNull($u->email_verified_at);
        $this->assertSame(['miembro_comision'],DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->pluck('role_code')->all());
        $this->assertSame(0,DB::table('cchia_admissions.membership_registrations')->count());
        $this->signIn($u);$this->getJson($this->url($c))->assertOk();$this->getJson('/api/v1/session/me')->assertOk()->assertJsonPath('data.can_sponsor',false);
    }
    public function test_accept_recovery_does_not_create_second_account_or_change_password(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();$this->accept($invite)->assertOk();
        $this->accept($invite,['password'=>'OtraClave-987654','password_confirmation'=>'OtraClave-987654'])->assertOk()->assertJsonPath('data.replayed',true);
        $u=User::query()->where('email','nueva@example.test')->firstOrFail();$this->assertTrue(Hash::check('AccesoFicticio-12345',$u->password));
        $this->assertSame(1,DB::table('cchia_m02.role_assignments')->where('user_id',$u->id)->count());
    }
    public function test_existing_account_requires_its_own_session_and_never_changes_password(): void
    {
        $c=$this->commission();$u=$this->account(null,null,'existente@example.test');$invite=$this->invitation($c,$u->email);$this->guest();
        $this->accept($invite)->assertConflict();$this->signIn($this->director);$this->accept($invite)->assertConflict();
        $this->signIn($u);$this->accept($invite,['create_account'=>false])->assertOk();$this->assertTrue(Hash::check('ClaveFicticia-123456',$u->fresh()->password));
    }
    public function test_invalid_expired_and_cancelled_codes_cannot_create_access(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();
        $this->accept([...$invite,'code'=>str_repeat('A',43)])->assertNotFound();
        DB::table('cchia_m02.role_invitations')->where('id',$invite['invitation_id'])->update(['expires_at'=>now()]);$this->accept($invite)->assertNotFound();
        $this->assertFalse(User::query()->where('email','nueva@example.test')->exists());
    }
    public function test_cancel_prevents_acceptance_and_purges_pending_mail_secret(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');
        $this->postAs($this->director,$this->url($c,'/invitations/'.$invite['invitation_id'].'/cancel'),['reason'=>'Invitación ficticia cancelada.','confirmation'=>true])->assertOk();
        $this->assertNull(DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$invite['invitation_id'])->value('token_encrypted'));
        $this->guest();$this->accept($invite)->assertNotFound();app(WorkspaceMailOutbox::class)->deliver();Mail::assertNothingSent();
    }
    public function test_issuer_revocation_and_permission_removal_invalidate_pending_invitation(): void
    {
        $c=$this->commission();$leader=$this->leader($c);$invite=$this->invitation($c,'nueva@example.test',$leader);
        DB::table('cchia_m02.role_assignments')->where('user_id',$leader->id)->update(['status'=>'revoked','revoked_at'=>now()]);$this->guest();$this->accept($invite)->assertNotFound();
        DB::table('cchia_m02.role_assignments')->where('user_id',$leader->id)->update(['status'=>'active','revoked_at'=>null]);
        DB::table('cchia_m02.role_permissions')->where('role_code','lider_comision')->where('permission_code','commission.members.invite_member')->delete();$this->accept($invite)->assertNotFound();
    }
    public function test_invited_role_cannot_outlast_limited_issuer_assignment(): void
    {
        $c=$this->commission();$leader=$this->leader($c);$end=now()->addDays(3);
        DB::table('cchia_m02.role_assignments')->where('user_id',$leader->id)->update(['ends_at'=>$end]);
        $this->postAs($leader,$this->url($c,'/invitations'),['email'=>'nueva@example.test','role_code'=>'miembro_comision','ends_at'=>null,'appointment_reference'=>null,'confirmation'=>true])->assertUnprocessable();
        $invite=$this->invitation($c,'nueva@example.test',$leader,'miembro_comision',now()->addDays(2)->toIso8601String());
        $this->assertEquals(now()->addDays(2)->timestamp,now()->parse(DB::table('cchia_m02.role_invitations')->where('id',$invite['invitation_id'])->value('expires_at'))->timestamp);
    }
    public function test_decline_is_explicit_and_does_not_attribute_recipient_action_to_inviter(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();
        $this->postJson('/api/v1/workspace/invitations/decline',[...$invite,'confirmation'=>false])->assertUnprocessable();
        $this->postJson('/api/v1/workspace/invitations/decline',[...$invite,'confirmation'=>true])->assertOk();
        $this->postJson('/api/v1/workspace/invitations/decline',[...$invite,'confirmation'=>true])->assertOk()->assertJsonPath('data.replayed',true);
        $event=DB::table('cchia_workspace.events')->where('action','invitation.declined_by_recipient')->first();$this->assertNull($event->actor_user_id);
        $this->assertFalse(User::query()->where('email','nueva@example.test')->exists());
    }
    public function test_expired_invitation_is_replaced_with_new_code_and_history_preserved(): void
    {
        $c=$this->commission();$old=$this->invitation($c,'nueva@example.test');DB::table('cchia_m02.role_invitations')->where('id',$old['invitation_id'])->update(['expires_at'=>now()]);
        $new=$this->invitation($c,'nueva@example.test');$this->assertNotSame($old['code'],$new['code']);
        $this->assertSame('expired',DB::table('cchia_m02.role_invitations')->where('id',$old['invitation_id'])->value('status'));
        $this->guest();$this->accept($old)->assertNotFound();$this->accept($new)->assertOk();
    }
    public function test_archived_commission_rejects_pending_acceptance_without_creating_account(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');
        $this->postAs($this->director,$this->url($c),['name'=>'Comisión Ficticia','description'=>'','status'=>'archived','version'=>1])->assertOk();
        $this->guest();$this->accept($invite)->assertConflict();$this->assertFalse(User::query()->where('email','nueva@example.test')->exists());
    }
    public function test_mail_contains_private_code_in_body_but_never_in_url_and_is_sent_once(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$result=app(WorkspaceMailOutbox::class)->deliver();$this->assertSame(1,$result['sent']);
        Mail::assertSent(WorkspaceInvitation::class,function($mail)use($invite){
            $html=$mail->render();$this->assertStringContainsString($invite['code'],$html);
            $this->assertDoesNotMatchRegularExpression('/href="[^"]*'.preg_quote($invite['code'],'/').'/', $html);return $mail->hasTo('nueva@example.test');
        });
        app(WorkspaceMailOutbox::class)->deliver();Mail::assertSentCount(1);
        $this->assertNull(DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$invite['invitation_id'])->value('token_encrypted'));
    }
    public function test_mail_failure_does_not_undo_invitation_and_has_bounded_backoff(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');
        Mail::shouldReceive('to')->once()->andReturnSelf();Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('fallo ficticio'));
        $r=app(WorkspaceMailOutbox::class)->deliver();$this->assertSame(1,$r['failed']);
        $this->assertSame('pending',DB::table('cchia_m02.role_invitations')->where('id',$invite['invitation_id'])->value('status'));
        $this->assertSame(1,DB::table('cchia_workspace.mail_outbox')->value('attempts'));$this->assertSame(0,app(WorkspaceMailOutbox::class)->deliver()['processed']);
        $this->travel(61)->seconds();Mail::swap(new \Illuminate\Mail\MailManager($this->app));Mail::fake();$this->assertSame(1,app(WorkspaceMailOutbox::class)->deliver()['sent']);
    }
    public function test_invitation_acceptance_cannot_override_role_or_scope(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();
        $this->accept($invite,['role_code'=>'directorio'])->assertUnprocessable();$this->accept($invite,['scope_id'=>(string)Str::uuid()])->assertUnprocessable();
    }
    public function test_exhausted_mail_retries_purge_the_private_code_and_keep_history(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'agotada@example.test');
        Mail::shouldReceive('to')->times(5)->andReturnSelf();Mail::shouldReceive('send')->times(5)->andThrow(new \RuntimeException('fallo ficticio'));
        for($i=0;$i<5;$i++){
            $this->assertSame(1,app(WorkspaceMailOutbox::class)->deliver()['failed']);$this->travel(3601)->seconds();
        }
        $row=DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$invite['invitation_id'])->first();
        $this->assertSame(5,(int)$row->attempts);$this->assertNotNull($row->abandoned_at);$this->assertNull($row->token_encrypted);
        $this->assertSame(0,app(WorkspaceMailOutbox::class)->deliver()['processed']);
        $this->assertSame('pending',DB::table('cchia_m02.role_invitations')->where('id',$invite['invitation_id'])->value('status'));
    }
    public function test_local_permission_preparation_is_disabled_in_production(): void
    {
        $this->app['env']='production';$this->artisan('workspace:local-permissions',['email'=>$this->director->email])->assertExitCode(1);
    }
    public function test_profile_lost_response_recovers_same_operation_without_second_update(): void
    {
        $u=$this->account();$key=(string)Str::uuid();$body=['name'=>'Persona Actualizada','biography'=>'Privada','share_biography'=>false,'version'=>0];
        $this->postAs($u,'/api/v1/profile',$body,$key)->assertOk();$this->postAs($u,'/api/v1/profile',$body,$key)->assertOk()->assertJsonPath('data.version',1);
        $this->assertSame(1,DB::table('cchia_workspace.events')->where('action','profile.updated')->count());
    }
    public function test_storage_failure_rolls_back_resource_and_idempotency_record(): void
    {
        $c=$this->commission();$before=DB::table('cchia_workspace.operations')->count();
        $disk=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);$disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('fallo ficticio'));
        $disk->shouldReceive('delete')->once()->andReturn(true);Storage::shouldReceive('disk')->with('workspace-private')->andReturn($disk);
        $this->signIn($this->director);$this->post($this->url($c,'/resources'),['title'=>'Prueba','file'=>$this->pdf()],['Accept'=>'application/json','Idempotency-Key'=>(string)Str::uuid()])->assertServerError();
        $this->assertSame(0,DB::table('cchia_workspace.resources')->count());$this->assertSame($before,DB::table('cchia_workspace.operations')->count());
    }
    public function test_resource_checks_detect_corruption_and_missing_data_without_disclosing_paths(): void
    {
        $c=$this->commission();$this->signIn($this->director);$id=$this->post($this->url($c,'/resources'),['title'=>'Recurso','file'=>$this->pdf()],['Accept'=>'application/json','Idempotency-Key'=>(string)Str::uuid()])->assertOk()->json('data.id');
        $service=app(\App\Services\WorkspaceChecks::class);$this->assertTrue($service->files()['ok']);$row=DB::table('cchia_workspace.resources')->where('id',$id)->first();
        Storage::disk('workspace-private')->put($row->storage_key,'alterado');$check=$service->files();$this->assertFalse($check['ok']);$this->assertSame('integrity_mismatch',$check['issues'][0]['reason']);
        $this->assertStringNotContainsString($row->storage_key,json_encode($check));Storage::disk('workspace-private')->delete($row->storage_key);$this->assertSame('missing_or_unreadable',$service->files()['issues'][0]['reason']);
    }
    public function test_resource_integrity_limit_reports_incomplete_check(): void
    {
        $c=$this->commission();$this->signIn($this->director);
        foreach(['Uno','Dos'] as $title)$this->post($this->url($c,'/resources'),['title'=>$title,'file'=>$this->pdf()],['Accept'=>'application/json','Idempotency-Key'=>(string)Str::uuid()])->assertOk();
        $r=app(\App\Services\WorkspaceChecks::class)->files(1);$this->assertFalse($r['ok']);$this->assertFalse($r['complete']);$this->assertSame(1,$r['checked']);
    }
    public function test_existing_invitation_cannot_be_transferred_by_changing_bound_account_email(): void
    {
        $c=$this->commission();$u=$this->account(null,null,'original@example.test');$invite=$this->invitation($c,$u->email);$u->forceFill(['email'=>'cambiado@example.test'])->save();
        $this->guest();$this->accept($invite)->assertNotFound();$this->assertFalse(User::query()->where('email','original@example.test')->exists());
    }
    public function test_missing_confirmation_or_weak_new_password_never_creates_account(): void
    {
        $c=$this->commission();$invite=$this->invitation($c,'nueva@example.test');$this->guest();
        $this->accept($invite,['confirmation'=>false])->assertUnprocessable();$this->accept($invite,['password'=>'corta','password_confirmation'=>'corta'])->assertUnprocessable();
        $this->assertFalse(User::query()->where('email','nueva@example.test')->exists());
    }
    public function test_operations_command_is_discovered_and_rejects_invalid_arguments(): void
    {
        $this->artisan('workspace:ops',['action'=>'summary','--json'=>true])->assertExitCode(0);
        $this->artisan('workspace:ops',['action'=>'files','--full'=>true,'--json'=>true])->assertExitCode(0);
        $this->artisan('workspace:ops',['action'=>'unknown'])->assertExitCode(1);
        $this->artisan('workspace:ops',['action'=>'files','--limit'=>'0'])->assertExitCode(1);
    }
    public function test_invitation_issue_recovery_creates_only_one_outbox_entry(): void
    {
        $c=$this->commission();$key=(string)Str::uuid();$body=['email'=>'nueva@example.test','role_code'=>'miembro_comision','appointment_reference'=>null,'confirmation'=>true];
        $id=$this->postAs($this->director,$this->url($c,'/invitations'),$body,$key)->assertOk()->json('data.id');
        $this->postAs($this->director,$this->url($c,'/invitations'),$body,$key)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.id',$id);
        $this->assertSame(1,DB::table('cchia_workspace.mail_outbox')->count());
    }
    #[Group('native-client')]
    public function test_evidence_and_resources_cannot_be_rewritten_with_sql(): void
    {
        $c=$this->commission();$row=DB::table('cchia_workspace.events')->where('commission_id',$c['id'])->first();
        try{DB::transaction(fn()=>DB::table('cchia_workspace.events')->where('id',$row->id)->update(['action'=>'alterada']));$this->fail('Debe impedir cambios.');}catch(\Illuminate\Database\QueryException $e){$this->assertSame('23514',$e->errorInfo[0]);}
        $this->assertSame('commission.created',DB::table('cchia_workspace.events')->where('id',$row->id)->value('action'));
    }
}
