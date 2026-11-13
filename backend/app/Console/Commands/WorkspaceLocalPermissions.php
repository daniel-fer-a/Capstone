<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Model02Access;
use App\Services\WorkspaceAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WorkspaceLocalPermissions extends Command
{
    protected $signature='workspace:local-permissions {email}';
    protected $description='Prepara el permiso de administración de comisiones solo en local/testing para un Directorio ficticio.';
    public function handle(): int
    {
        if(!app()->environment(['local','testing'])){$this->error('Solo se permite en local o testing.');return 1;}
        $user=User::query()->where('email',mb_strtolower(trim($this->argument('email'))))->first();
        if(!$user||!app(Model02Access::class)->effectiveGlobalAssignments($user->id,now())->where('ra.role_code','directorio')->exists()){$this->error('Se requiere una cuenta de Directorio global vigente.');return 1;}
        DB::transaction(function()use($user){
            DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code'=>'directorio','permission_code'=>'workspace.commissions.manage']);
            app(WorkspaceAccess::class)->event($user,null,'development.workspace_permissions_prepared','role','directorio');
        });
        $this->info('Permiso de QA preparado para el rol Directorio. No habilita la función ni modifica permisos productivos.');return 0;
    }
}
