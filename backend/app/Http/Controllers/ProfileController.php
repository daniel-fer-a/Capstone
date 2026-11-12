<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceAccess;
use App\Services\WorkspaceMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $u=$request->user();$p=DB::table('cchia_workspace.profiles')->where('user_id',$u->id)->first();
        return response()->json(['data'=>['name'=>$u->name,'email'=>$u->email,'biography'=>$p?->biography??'',
            'share_biography'=>(bool)($p?->share_biography??false),'version'=>(int)($p?->version??0)]])->header('Cache-Control','no-store');
    }

    public function update(Request $request): JsonResponse
    {
        foreach(['name','biography'] as $f)if(is_string($request->input($f)))$request->merge([$f=>trim($request->input($f))]);
        $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],
            'biography'=>['present','nullable','string','max:1000','not_regex:/\x00/'],'share_biography'=>['required','boolean'],
            'version'=>['required','integer','min:0'],'email'=>['prohibited'],'role'=>['prohibited'],'password'=>['prohibited']]);
        $actor=$request->user();
        $mutation=app(WorkspaceMutation::class);
        $mutation->run($actor,'profile.update',$mutation->key($request),$data,function()use($actor,$data){
            DB::table('public.users')->where('id',$actor->id)->lockForUpdate()->first();
            $row=DB::table('cchia_workspace.profiles')->where('user_id',$actor->id)->first();
            abort_unless((int)($row?->version??0)===(int)$data['version'],409,'El perfil cambió. Recárgalo antes de guardar.');
            $actor->forceFill(['name'=>$data['name']])->save();
            DB::table('cchia_workspace.profiles')->upsert(['user_id'=>$actor->id,'biography'=>$data['biography']??'',
                'share_biography'=>$data['share_biography'],'version'=>$data['version']+1,'updated_at'=>now()],['user_id']);
            app(WorkspaceAccess::class)->event($actor,null,'profile.updated','profile',$actor->id,['fields'=>['name','biography','share_biography']]);
            return ['id'=>$actor->id,'version'=>(int)$data['version']+1];
        });
        return $this->show($request);
    }

    public function password(Request $request): JsonResponse
    {
        $data=$request->validate(['current_password'=>['required','string','max:1024'],'password'=>['required','string','confirmed',Password::min(12)->letters()->numbers(),
            function($a,$v,$fail){if(is_string($v)&&(strlen($v)>72 || str_contains($v,"\0")))$fail('Usa una contraseña de hasta 72 bytes y sin caracteres nulos.');}]]);
        $actor=$request->user();
        DB::transaction(function()use($actor,$data){
            $user=\App\Models\User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if(!Hash::check($data['current_password'],$user->password))throw ValidationException::withMessages(['current_password'=>'La contraseña actual no es correcta.']);
            if(Hash::check($data['password'],$user->password))throw ValidationException::withMessages(['password'=>'Usa una contraseña diferente.']);
            $user->forceFill(['password'=>$data['password'],'remember_token'=>Str::random(60)])->save();$user->tokens()->delete();
            app(WorkspaceAccess::class)->event($actor,null,'profile.password_changed','profile',$actor->id);
        });
        Auth::guard('web')->logout();$request->session()->invalidate();$request->session()->regenerateToken();
        return response()->json(['data'=>['signed_out'=>true]])->header('Cache-Control','no-store');
    }
}
