<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkspaceChecks
{
    public function summary(): array
    {
        $tables=[];foreach(['profiles','commissions','projects','tasks','invitation_details','mail_outbox','resources','events','operations'] as $t)$tables[$t]=DB::scalar('SELECT to_regclass(?)',['cchia_workspace.'.$t])!==null;
        $pending=DB::table('cchia_workspace.mail_outbox')->whereNull('sent_at')->whereNull('abandoned_at')->count();
        $failed=DB::table('cchia_workspace.mail_outbox')->whereNull('sent_at')->whereNotNull('abandoned_at')->whereNotNull('last_error_type')->count();
        $unsupported=DB::table('cchia_m02.role_permissions')->where('permission_code','workspace.commissions.manage')->where('role_code','!=','directorio')->count();
        return ['ok'=>!in_array(false,$tables,true)&&$failed===0&&$unsupported===0,'enabled'=>(bool)config('workspace.enabled'),'tables'=>$tables,
            'commission_count'=>DB::table('cchia_workspace.commissions')->count(),'project_count'=>DB::table('cchia_workspace.projects')->count(),
            'resource_count'=>DB::table('cchia_workspace.resources')->count(),'pending_notices'=>$pending,'failed_notices'=>$failed,'unsupported_admin_grants'=>$unsupported];
    }

    public function files(int $limit=250,bool $full=false): array
    {
        $q=DB::table('cchia_workspace.resources')->orderBy('id');$total=(clone $q)->count();$checked=0;$issues=[];
        if(!$full)$q->limit($limit);$disk=Storage::disk(config('workspace.disk'));
        foreach($q->cursor() as $row){
            $checked++;$pattern='/^'.preg_quote($row->commission_id,'/').'\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(pdf|jpg|png)$/D';
            if(!preg_match($pattern,$row->storage_key)){$issues[]=['id'=>$row->id,'reason'=>'invalid_storage_key'];continue;}
            try{
                $stream=$disk->readStream($row->storage_key);if(!is_resource($stream))throw new \RuntimeException();
                $hash=hash_init('sha256');$size=0;
                try{while(!feof($stream)){$chunk=fread($stream,65536);if($chunk===false)throw new \RuntimeException();$size+=strlen($chunk);hash_update($hash,$chunk);}}finally{fclose($stream);}
                if($size!==(int)$row->byte_size||!hash_equals($row->sha256,hash_final($hash)))$issues[]=['id'=>$row->id,'reason'=>'integrity_mismatch'];
            }catch(\Throwable){$issues[]=['id'=>$row->id,'reason'=>'missing_or_unreadable'];}
        }
        return ['ok'=>$checked===$total&&$issues===[],'complete'=>$checked===$total,'checked'=>$checked,'total'=>$total,'issues'=>$issues];
    }
}
