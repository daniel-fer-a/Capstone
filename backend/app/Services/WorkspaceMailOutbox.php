<?php

namespace App\Services;

use App\Mail\WorkspaceInvitation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WorkspaceMailOutbox
{
    public function deliver(int $limit=25): array
    {
        $result=['processed'=>0,'sent'=>0,'failed'=>0,'omitted'=>0];
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $outcome=DB::transaction(function(){
                $candidate=DB::table('cchia_workspace.mail_outbox as o')->join('cchia_workspace.invitation_details as d','d.invitation_id','=','o.invitation_id')
                    ->whereNull('o.sent_at')->whereNull('o.abandoned_at')->where('o.attempts','<',5)->where('o.available_at','<=',now())
                    ->orderBy('o.available_at')->orderBy('o.id')->first(['o.id','d.commission_id']);
                if(!$candidate)return null;
                if(!DB::table('cchia_workspace.commissions')->where('commission_id',$candidate->commission_id)->lock('FOR UPDATE SKIP LOCKED')->first())return null;
                $row=DB::table('cchia_workspace.mail_outbox')->where('id',$candidate->id)->whereNull('sent_at')->whereNull('abandoned_at')->where('attempts','<',5)
                    ->where('available_at','<=',now())->lock('FOR UPDATE SKIP LOCKED')->first();
                if(!$row)return null;
                $invitation=app(WorkspaceInvitations::class)->find($row->invitation_id);
                try{
                    if(!$invitation||$row->token_encrypted===null)throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
                    $commission=app(WorkspaceInvitations::class)->live($invitation);
                }catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){
                    if($e->getStatusCode()===503)return null;
                    DB::table('cchia_workspace.mail_outbox')->where('id',$row->id)->update(['abandoned_at'=>now(),'token_encrypted'=>null,'locked_at'=>null]);return 'omitted';
                }
                try{
                    $token=Crypt::decryptString($row->token_encrypted);
                    $role=$invitation->role_code==='lider_comision'?'Líder de comisión':'Miembro de comisión';
                    Mail::to($invitation->invitee_email)->send(new WorkspaceInvitation($invitation->id,$token,$commission->name,$role,$invitation->expires_at));
                    DB::table('cchia_workspace.mail_outbox')->where('id',$row->id)->update(['sent_at'=>now(),'attempts'=>$row->attempts+1,'locked_at'=>null,'last_error_type'=>null,'token_encrypted'=>null]);return 'sent';
                }catch(\Throwable $e){
                    $attempts=$row->attempts+1;
                    DB::table('cchia_workspace.mail_outbox')->where('id',$row->id)->update(['attempts'=>$attempts,'locked_at'=>null,'last_error_type'=>get_class($e),
                        'available_at'=>now()->addSeconds(min(3600,30*(2**$attempts))),'abandoned_at'=>$attempts>=5?now():null,'token_encrypted'=>$attempts>=5?null:$row->token_encrypted]);return 'failed';
                }
            });
            if($outcome===null)break;$result['processed']++;$result[$outcome]++;
        }
        return $result;
    }
}
