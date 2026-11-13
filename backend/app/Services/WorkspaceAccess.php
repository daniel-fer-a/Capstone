<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceAccess
{
    public function enabled(): void
    {
        abort_unless(config('workspace.enabled'),503,'El espacio de colaboración no está habilitado.');
    }

    public function administrator(User $actor): bool
    {
        return app(Model02Access::class)->effectiveGlobalAssignments($actor->id,now())
            ->join('cchia_m02.role_permissions as rp','rp.role_code','=','ra.role_code')
            ->where('ra.role_code','directorio')->where('rp.permission_code','workspace.commissions.manage')->exists();
    }

    public function assignments(string $scope,?string $user=null): Builder
    {
        $q=DB::table('cchia_m02.role_assignments as ra')->join('cchia_m02.roles as r','r.code','=','ra.role_code')
            ->join('cchia_m02.access_scopes as s','s.id','=','ra.scope_id')
            ->where('ra.scope_id',$scope)->where('s.kind','commission')->where('r.default_scope','commission')->where('r.is_active',true)
            ->whereIn('ra.role_code',['lider_comision','miembro_comision'])->where('ra.status','active')->whereNull('ra.revoked_at')
            ->where('ra.starts_at','<=',now())->where(fn($q)=>$q->whereNull('ra.ends_at')->orWhere('ra.ends_at','>',now()));
        if($user!==null)$q->where('ra.user_id',$user);
        return $q;
    }

    public function leaderPermission(string $scope,string $user,string $permission): bool
    {
        return $this->assignments($scope,$user)->join('cchia_m02.role_permissions as rp','rp.role_code','=','ra.role_code')
            ->where('ra.role_code','lider_comision')->where('rp.permission_code',$permission)->exists();
    }

    public function commission(User $actor,string $id,bool $write=false): object
    {
        $this->enabled();
        $query=DB::table('cchia_workspace.commissions as w')->join('public.commissions as c','c.id','=','w.commission_id')->where('w.commission_id',$id);
        if($write)$query->lock('FOR UPDATE OF w');
        $row=$query->first(['w.*','c.name']);
        abort_unless($row && ($this->administrator($actor)||$this->assignments($row->scope_id,$actor->id)->exists()),404);
        if($write)abort_unless($row->status==='active',409,'La comisión está archivada y conserva solo consultas.');
        return $row;
    }

    public function manage(User $actor,object $commission): bool
    {
        return $this->administrator($actor)||$this->leaderPermission($commission->scope_id,$actor->id,'commission.manage');
    }

    public function invite(User $actor,object $commission,string $role): bool
    {
        if($this->administrator($actor))return in_array($role,['lider_comision','miembro_comision'],true);
        return $role==='miembro_comision' && $this->leaderPermission($commission->scope_id,$actor->id,'commission.members.invite_member');
    }

    public function event(?User $actor,?string $commission,string $action,string $type,string $id,array $metadata=[]): void
    {
        DB::table('cchia_workspace.events')->insert(['id'=>(string)Str::uuid(),'commission_id'=>$commission,'actor_user_id'=>$actor?->id,
            'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'metadata'=>json_encode((object)$metadata,JSON_THROW_ON_ERROR),'occurred_at'=>now()]);
    }
}
