<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceMutation
{
    public function key(Request $request): string
    {
        $data=validator(['key'=>$request->header('Idempotency-Key')],['key'=>['required','string','min:32','max:128','regex:/^[A-Za-z0-9_-]+$/D']])->validate();
        return $data['key'];
    }

    public function run(User $actor,string $action,string $key,array $payload,callable $operation): array
    {
        $hash=hash('sha256',$actor->id.':'.$key);
        $payloadHash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$action,$hash,$payloadHash,$operation){
            DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr($hash,0,15))]);
            $previous=DB::table('cchia_workspace.operations')->where('operation_hash',$hash)->first();
            if($previous){
                abort_unless($previous->action===$action && hash_equals($previous->payload_hash,$payloadHash),409,'La operación ya se utilizó con otros datos.');
                return [...json_decode($previous->result,true,flags:JSON_THROW_ON_ERROR),'replayed'=>true];
            }
            $result=$operation();
            DB::table('cchia_workspace.operations')->insert(['id'=>(string)Str::uuid(),'actor_user_id'=>$actor->id,'action'=>$action,
                'operation_hash'=>$hash,'payload_hash'=>$payloadHash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return [...$result,'replayed'=>false];
        });
    }
}
