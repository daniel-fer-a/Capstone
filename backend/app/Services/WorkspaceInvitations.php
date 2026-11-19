<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class WorkspaceInvitations
{
    public function __construct(private readonly WorkspaceAccess $access,private readonly WorkspaceMutation $mutation) {}

    public function issue(string $commission,Request $request): array
    {
        $c=$this->access->commission($request->user(),$commission,true);
        foreach(['email','appointment_reference'] as $f)if(is_string($request->input($f)))$request->merge([$f=>trim($request->input($f))]);
        if(is_string($request->input('email')))$request->merge(['email'=>mb_strtolower($request->input('email'))]);
        $data=$request->validate(['email'=>['required','string','email:rfc','max:255'],'role_code'=>['required','in:lider_comision,miembro_comision'],
            'ends_at'=>['sometimes','nullable','date','after:now'],'appointment_reference'=>['present','nullable','string','max:255','not_regex:/\x00/'],
            'confirmation'=>['required','accepted']]);$actor=$request->user();
        abort_unless($this->access->invite($actor,$c,$data['role_code']),403);
        if($data['email']===mb_strtolower($actor->email))throw ValidationException::withMessages(['email'=>'La invitación debe dirigirse a otra persona.']);
        if($data['role_code']==='lider_comision'&&mb_strlen($data['appointment_reference']??'')<5)throw ValidationException::withMessages(['appointment_reference'=>'Indica la referencia de designación del liderazgo.']);
        $end=empty($data['ends_at'])?null:now()->parse($data['ends_at']);
        $data['ends_at']=$end?->toIso8601String();
        return $this->mutation->run($actor,'invitation.issue',$this->mutation->key($request),[$commission,$data],function()use($actor,$commission,$data,$end){
            $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->invite($actor,$c,$data['role_code']),403);
            if(!$this->access->administrator($actor)){
                $authority=$this->access->assignments($c->scope_id,$actor->id)->where('ra.role_code','lider_comision')->orderByRaw('ra.ends_at DESC NULLS FIRST')->first(['ra.ends_at']);
                if($authority?->ends_at!==null && ($end===null || $end->greaterThan($authority->ends_at)))throw ValidationException::withMessages(['ends_at'=>'La asignación no puede superar la vigencia del liderazgo que invita.']);
            }
            $role=DB::table('cchia_m02.roles')->where('code',$data['role_code'])->where('is_active',true)->where('default_scope','commission')->first();abort_unless($role,409);
            DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','invite:'.$c->scope_id.':'.$data['role_code'].':'.$data['email']),0,15))]);
            $expired=DB::table('cchia_m02.role_invitations')->where('scope_id',$c->scope_id)->where('role_code',$data['role_code'])->where('invitee_email',$data['email'])->where('status','pending')->where('expires_at','<=',now())->pluck('id');
            foreach($expired as $id){
                DB::table('cchia_m02.role_invitations')->where('id',$id)->update(['status'=>'expired']);
                DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$id)->whereNull('sent_at')->update(['abandoned_at'=>now(),'token_encrypted'=>null]);
                $this->access->event($actor,$commission,'invitation.expired','invitation',$id);
            }
            if(DB::table('cchia_m02.role_invitations')->where('scope_id',$c->scope_id)->where('role_code',$data['role_code'])->where('invitee_email',$data['email'])->where('status','pending')->exists())throw ValidationException::withMessages(['email'=>'Ya hay una invitación pendiente para ese rol y comisión.']);
            $user=User::query()->where('email',$data['email'])->first();
            if($user && $this->access->assignments($c->scope_id,$user->id)->where('ra.role_code',$data['role_code'])->exists())throw ValidationException::withMessages(['email'=>'La cuenta ya tiene ese rol vigente en esta comisión.']);
            $id=(string)Str::uuid();$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $expires=now()->addHours(config('workspace.invitation_hours'));if($end!==null&&$end->lessThan($expires))$expires=$end;
            DB::table('cchia_m02.role_invitations')->insert(['id'=>$id,'invitee_email'=>$data['email'],'invitee_user_id'=>$user?->id,'role_code'=>$data['role_code'],
                'scope_id'=>$c->scope_id,'invited_by_user_id'=>$actor->id,'status'=>'pending','token_hash'=>hash('sha256',$token),'expires_at'=>$expires,'created_at'=>now()]);
            DB::table('cchia_workspace.invitation_details')->insert(['invitation_id'=>$id,'commission_id'=>$commission,'assignment_ends_at'=>$end,'appointment_reference'=>$data['appointment_reference']]);
            DB::table('cchia_workspace.mail_outbox')->insert(['id'=>(string)Str::uuid(),'invitation_id'=>$id,'token_encrypted'=>Crypt::encryptString($token),'created_at'=>now(),'available_at'=>now()]);
            $this->access->event($actor,$commission,'invitation.issued','invitation',$id,['role_code'=>$data['role_code']]);
            return ['id'=>$id,'status'=>'pending','expires_at'=>$expires->toIso8601String()];
        });
    }

    public function find(string $id,bool $lock=false): ?object
    {
        $q=DB::table('cchia_m02.role_invitations as i')->join('cchia_workspace.invitation_details as d','d.invitation_id','=','i.id')
            ->where('i.id',$id);if($lock)$q->lockForUpdate();
        return $q->first(['i.*','d.commission_id','d.assignment_ends_at','d.assignment_id']);
    }

    public function live(object $row): object
    {
        $this->access->enabled();
        abort_unless($row->status==='pending'&&now()->lessThan($row->expires_at)
            &&($row->assignment_ends_at===null||now()->lessThan($row->assignment_ends_at)),404,'La invitación no está disponible.');
        if($row->invitee_user_id!==null)abort_unless(User::query()->whereKey($row->invitee_user_id)->where('email',$row->invitee_email)->exists(),404,'La invitación no está disponible.');
        $actor=User::query()->find($row->invited_by_user_id);abort_unless($actor,404);
        $c=$this->access->commission($actor,$row->commission_id,true);
        $role=DB::table('cchia_m02.roles')->where('code',$row->role_code)->where('is_active',true)->where('default_scope','commission')->exists();
        abort_unless($role && $this->access->invite($actor,$c,$row->role_code),404,'La invitación no está disponible.');
        if(!$this->access->administrator($actor)){
            $authority=$this->access->assignments($c->scope_id,$actor->id)->where('ra.role_code','lider_comision')->orderByRaw('ra.ends_at DESC NULLS FIRST')->first(['ra.ends_at']);
            abort_unless($authority && ($authority->ends_at===null || ($row->assignment_ends_at!==null&&now()->parse($row->assignment_ends_at)->lessThanOrEqualTo($authority->ends_at))),404);
        }
        return $c;
    }

    private function credentials(Request $request): array
    {
        return $request->validate(['invitation_id'=>['required','uuid'],'code'=>['required','string','min:43','max:128','regex:/^[A-Za-z0-9_-]+$/D']]);
    }

    private function owner(array $data,bool $lock=false): object
    {
        $row=$this->find($data['invitation_id'],$lock);abort_unless($row&&hash_equals($row->token_hash,hash('sha256',$data['code'])),404,'La invitación no está disponible.');return $row;
    }

    public function preview(Request $request): array
    {
        $this->access->enabled();$row=$this->owner($this->credentials($request));
        if($row->status==='accepted')return ['status'=>'accepted'];
        if($row->status==='declined')return ['status'=>'declined'];
        $c=$this->live($row);
        return ['id'=>$row->id,'status'=>'pending','email'=>$row->invitee_email,'role_code'=>$row->role_code,'commission_name'=>$c->name,
            'expires_at'=>$row->expires_at,'ends_at'=>$row->assignment_ends_at];
    }

    public function accept(Request $request): array
    {
        $this->access->enabled();$credentials=$this->credentials($request);
        $request->validate(['confirmation'=>['required','accepted'],'create_account'=>['required','boolean'],
            'role_code'=>['prohibited'],'scope_id'=>['prohibited'],'email'=>['prohibited']]);
        $initial=$this->owner($credentials);
        return DB::transaction(function()use($request,$credentials,$initial){
            DB::table('cchia_workspace.commissions')->where('commission_id',$initial->commission_id)->lockForUpdate()->first();
            $row=$this->owner($credentials,true);
            if($row->status==='accepted')return ['status'=>'accepted','replayed'=>true];
            $c=$this->live($row);
            DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','workspace-user:'.$row->invitee_email),0,15))]);
            $user=User::query()->where('email',$row->invitee_email)->lockForUpdate()->first();$created=false;
            if($user){
                abort_unless($request->user('web')?->id===$user->id,409,'Ingresa a la cuenta que recibió la invitación para aceptarla.');
            }else{
                abort_unless($request->boolean('create_account'),409,'Completa la creación del acceso privado para aceptar.');
                if($request->user('web'))abort(409,'Cierra la sesión actual antes de crear otro acceso.');
                $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],
                    'password'=>['required','string','confirmed',Password::min(12)->letters()->numbers(),function($a,$v,$fail){if(is_string($v)&&(strlen($v)>72||str_contains($v,"\0")))$fail('La contraseña excede el límite permitido.');}]]);
                $user=new User();$user->forceFill(['name'=>trim($data['name']),'email'=>$row->invitee_email,'password'=>$data['password'],'email_verified_at'=>now()])->save();$created=true;
            }
            $old=DB::table('cchia_m02.role_assignments')->where('user_id',$user->id)->where('scope_id',$row->scope_id)->where('role_code',$row->role_code)
                ->whereIn('status',['pending','active'])->lockForUpdate()->get();
            foreach($old as $assignment){
                abort_unless($assignment->ends_at!==null&&now()->greaterThanOrEqualTo($assignment->ends_at),409,'La cuenta ya tiene una asignación vigente para ese rol.');
                DB::table('cchia_m02.role_assignments')->where('id',$assignment->id)->update(['status'=>'expired']);
            }
            $id=(string)Str::uuid();DB::table('cchia_m02.role_assignments')->insert(['id'=>$id,'user_id'=>$user->id,'role_code'=>$row->role_code,
                'scope_id'=>$row->scope_id,'status'=>'active','assigned_by_user_id'=>$row->invited_by_user_id,'accepted_at'=>now(),
                'starts_at'=>now(),'ends_at'=>$row->assignment_ends_at,'source'=>'workspace_invitation']);
            DB::table('cchia_m02.role_invitations')->where('id',$row->id)->update(['status'=>'accepted','invitee_user_id'=>$user->id,'accepted_at'=>now()]);
            DB::table('cchia_workspace.invitation_details')->where('invitation_id',$row->id)->update(['assignment_id'=>$id]);
            DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$row->id)->update(['token_encrypted'=>null]);
            if($user->email_verified_at===null)$user->forceFill(['email_verified_at'=>now()])->save();
            $this->access->event($user,$c->commission_id,'invitation.accepted','invitation',$row->id,['role_code'=>$row->role_code,'assignment_id'=>$id,'account_created'=>$created]);
            return ['status'=>'accepted','replayed'=>false];
        });
    }

    public function decline(Request $request): array
    {
        $this->access->enabled();$data=$this->credentials($request);$request->validate(['confirmation'=>['required','accepted']]);$initial=$this->owner($data);
        return DB::transaction(function()use($request,$data,$initial){
            DB::table('cchia_workspace.commissions')->where('commission_id',$initial->commission_id)->lockForUpdate()->first();$row=$this->owner($data,true);
            if($row->status==='declined')return ['status'=>'declined','replayed'=>true];
            $this->live($row);if($request->user('web'))abort_unless(mb_strtolower($request->user('web')->email)===$row->invitee_email,403);
            DB::table('cchia_m02.role_invitations')->where('id',$row->id)->update(['status'=>'declined']);
            DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$row->id)->update(['token_encrypted'=>null,'abandoned_at'=>now()]);
            $this->access->event($request->user('web'),$row->commission_id,'invitation.declined_by_recipient','invitation',$row->id,['confirmation_method'=>'private_code']);
            return ['status'=>'declined','replayed'=>false];
        });
    }

    public function cancel(string $commission,string $id,Request $request): array
    {
        $c=$this->access->commission($request->user(),$commission);abort_unless($this->access->invite($request->user(),$c,'miembro_comision'),403);
        if(is_string($request->input('reason')))$request->merge(['reason'=>trim($request->input('reason'))]);
        $data=$request->validate(['reason'=>['required','string','min:10','max:500','not_regex:/\x00/'],'confirmation'=>['required','accepted']]);$actor=$request->user();
        return $this->mutation->run($actor,'invitation.cancel',$this->mutation->key($request),[$commission,$id,$data],function()use($actor,$commission,$id,$data){
            DB::table('cchia_workspace.commissions')->where('commission_id',$commission)->lockForUpdate()->first();$c=$this->access->commission($actor,$commission);
            $row=$this->find($id,true);abort_unless($row&&$row->commission_id===$commission,404);abort_unless($this->access->invite($actor,$c,$row->role_code),403);
            abort_unless(in_array($row->status,['pending','cancelled'],true),409,'La invitación ya tiene una respuesta.');
            DB::table('cchia_m02.role_invitations')->where('id',$id)->update(['status'=>'cancelled']);
            DB::table('cchia_workspace.mail_outbox')->where('invitation_id',$id)->update(['abandoned_at'=>now(),'token_encrypted'=>null]);
            $this->access->event($actor,$commission,'invitation.cancelled','invitation',$id,['reason'=>$data['reason']]);return ['id'=>$id,'status'=>'cancelled'];
        });
    }
}
