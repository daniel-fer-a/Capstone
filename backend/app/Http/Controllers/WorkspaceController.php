<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceAccess;
use App\Services\WorkspaceMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceAccess $access,private readonly WorkspaceMutation $mutation) {}

    private function json(mixed $data): JsonResponse
    {
        return response()->json(['data'=>$data])->header('Cache-Control','no-store');
    }

    private function validateText(Request $request,array $fields): void
    {
        foreach($fields as $field)if(is_string($request->input($field)))$request->merge([$field=>trim($request->input($field))]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->enabled();$actor=$request->user();
        $filters=$request->validate(['q'=>['sometimes','string','max:100','not_regex:/\x00/'],'page'=>['sometimes','integer','min:1']]);
        $q=DB::table('cchia_workspace.commissions as w')->join('public.commissions as c','c.id','=','w.commission_id');
        if(!$this->access->administrator($actor))$q->whereExists(function($sub)use($actor){
            $sub->selectRaw('1')->from('cchia_m02.role_assignments as a')->join('cchia_m02.roles as r','r.code','=','a.role_code')
                ->whereColumn('a.scope_id','w.scope_id')->where('a.user_id',$actor->id)->where('a.status','active')->whereNull('a.revoked_at')
                ->where('a.starts_at','<=',now())->where(fn($x)=>$x->whereNull('a.ends_at')->orWhere('a.ends_at','>',now()))
                ->whereIn('a.role_code',['lider_comision','miembro_comision'])->where('r.is_active',true)->where('r.default_scope','commission');
        });
        if(isset($filters['q'])&&trim($filters['q'])!=='')$q->where('c.name','ilike','%'.addcslashes(trim($filters['q']),'%_\\').'%');
        $result=$q->orderBy('c.name')->orderBy('c.id')->paginate(20,['c.id','c.name','w.description','w.status','w.version']);
        return response()->json($result)->header('Cache-Control','no-store');
    }

    public function create(Request $request): JsonResponse
    {
        $this->access->enabled();abort_unless($this->access->administrator($request->user()),403);
        $this->validateText($request,['name','description']);
        $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],'description'=>['present','nullable','string','max:3000','not_regex:/\x00/']]);
        $actor=$request->user();
        return $this->json($this->mutation->run($actor,'commission.create',$this->mutation->key($request),$data,function()use($actor,$data){
            abort_unless($this->access->administrator($actor),403);
            $name=mb_strtolower($data['name']);DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','commission:'.$name),0,15))]);
            if(DB::table('cchia_workspace.commissions')->where('normalized_name',$name)->exists())throw ValidationException::withMessages(['name'=>'Ya existe una comisión con ese nombre.']);
            $id=(string)Str::uuid();$scope=(string)Str::uuid();
            DB::table('public.commissions')->insert(['id'=>$id,'name'=>$data['name'],'created_at'=>now()]);
            DB::table('cchia_m02.access_scopes')->insert(['id'=>$scope,'kind'=>'commission','commission_id'=>$id,'label'=>$data['name'],'created_at'=>now()]);
            DB::table('cchia_workspace.commissions')->insert(['commission_id'=>$id,'scope_id'=>$scope,'normalized_name'=>$name,
                'description'=>$data['description']??'','created_by_user_id'=>$actor->id]);
            $this->access->event($actor,$id,'commission.created','commission',$id);
            return ['id'=>$id,'version'=>1];
        }));
    }

    public function show(string $commission,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission);$active=$c->status==='active';
        $members=$this->access->assignments($c->scope_id)->join('public.users as u','u.id','=','ra.user_id')
            ->leftJoin('cchia_workspace.profiles as p','p.user_id','=','u.id')->orderBy('u.name')->orderBy('ra.id')->limit(501)
            ->get(['ra.id','ra.user_id','ra.role_code','ra.starts_at','ra.ends_at','u.name',DB::raw("CASE WHEN p.share_biography THEN p.biography ELSE NULL END AS biography")]);
        return $this->json(['id'=>$commission,'name'=>$c->name,'description'=>$c->description,'status'=>$c->status,'version'=>(int)$c->version,
            'can_manage'=>$active&&$this->access->manage($request->user(),$c),'can_manage_commission'=>$this->access->administrator($request->user()),
            'can_invite_members'=>$active&&$this->access->invite($request->user(),$c,'miembro_comision'),
            'can_invite_leaders'=>$active&&$this->access->invite($request->user(),$c,'lider_comision'),
            'members'=>$members->take(500)->values(),'members_truncated'=>$members->count()>500]);
    }

    public function update(string $commission,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission);abort_unless($this->access->administrator($request->user()),403);
        $this->validateText($request,['name','description']);
        $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],'description'=>['present','nullable','string','max:3000','not_regex:/\x00/'],
            'status'=>['required','in:active,archived'],'version'=>['required','integer','min:1']]);
        $actor=$request->user();
        return $this->json($this->mutation->run($actor,'commission.update',$this->mutation->key($request),[$commission,$data],function()use($actor,$commission,$data){
            $c=DB::table('cchia_workspace.commissions')->where('commission_id',$commission)->lockForUpdate()->first();
            abort_unless($this->access->administrator($actor),403);abort_unless((int)$c->version===(int)$data['version'],409,'La comisión cambió. Recarga la ficha.');
            $name=mb_strtolower($data['name']);DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','commission:'.$name),0,15))]);
            if(DB::table('cchia_workspace.commissions')->where('normalized_name',$name)->where('commission_id','!=',$commission)->exists())throw ValidationException::withMessages(['name'=>'Ya existe una comisión con ese nombre.']);
            DB::table('public.commissions')->where('id',$commission)->update(['name'=>$data['name']]);
            DB::table('cchia_m02.access_scopes')->where('id',$c->scope_id)->update(['label'=>$data['name']]);
            DB::table('cchia_workspace.commissions')->where('commission_id',$commission)->update(['normalized_name'=>$name,'description'=>$data['description']??'',
                'status'=>$data['status'],'version'=>$c->version+1,'updated_at'=>now()]);
            $this->access->event($actor,$commission,'commission.updated','commission',$commission,['status'=>$data['status'],'version'=>$c->version+1]);
            return ['id'=>$commission,'version'=>$c->version+1];
        }));
    }

    public function projects(string $commission,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission);$request->validate(['page'=>['sometimes','integer','min:1']]);
        return response()->json(DB::table('cchia_workspace.projects')->where('commission_id',$commission)->orderByDesc('created_at')->orderBy('id')
            ->paginate(20,['id','name','description','status','version','created_at','updated_at']))->header('Cache-Control','no-store');
    }

    public function createProject(string $commission,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission,true);abort_unless($this->access->manage($request->user(),$c),403);
        $this->validateText($request,['name','description']);
        $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],'description'=>['present','nullable','string','max:3000','not_regex:/\x00/']]);
        $actor=$request->user();
        return $this->json($this->mutation->run($actor,'project.create',$this->mutation->key($request),[$commission,$data],function()use($actor,$commission,$data){
            $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->manage($actor,$c),403);
            DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','project:'.$commission.':'.mb_strtolower($data['name'])),0,15))]);
            if(DB::table('cchia_workspace.projects')->where('commission_id',$commission)->whereRaw('lower(name)=?',[mb_strtolower($data['name'])])->exists())throw ValidationException::withMessages(['name'=>'Ya existe un proyecto con ese nombre en esta comisión.']);
            $id=(string)Str::uuid();DB::table('cchia_workspace.projects')->insert(['id'=>$id,'commission_id'=>$commission,'name'=>$data['name'],
                'description'=>$data['description']??'','created_by_user_id'=>$actor->id]);
            $this->access->event($actor,$commission,'project.created','project',$id);return ['id'=>$id,'version'=>1];
        }));
    }

    public function updateProject(string $commission,string $project,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission,true);abort_unless($this->access->manage($request->user(),$c),403);
        $this->validateText($request,['name','description']);
        $data=$request->validate(['name'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],'description'=>['present','nullable','string','max:3000','not_regex:/\x00/'],
            'status'=>['required','in:active,archived'],'version'=>['required','integer','min:1']]);$actor=$request->user();
        return $this->json($this->mutation->run($actor,'project.update',$this->mutation->key($request),[$commission,$project,$data],function()use($actor,$commission,$project,$data){
            $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->manage($actor,$c),403);
            $row=DB::table('cchia_workspace.projects')->where('commission_id',$commission)->where('id',$project)->lockForUpdate()->first();abort_unless($row,404);
            abort_unless((int)$row->version===(int)$data['version'],409,'El proyecto cambió. Recarga la lista.');
            DB::select('SELECT pg_advisory_xact_lock(?)',[(int)hexdec(substr(hash('sha256','project:'.$commission.':'.mb_strtolower($data['name'])),0,15))]);
            if(DB::table('cchia_workspace.projects')->where('commission_id',$commission)->where('id','!=',$project)->whereRaw('lower(name)=?',[mb_strtolower($data['name'])])->exists())throw ValidationException::withMessages(['name'=>'Ya existe un proyecto con ese nombre.']);
            DB::table('cchia_workspace.projects')->where('id',$project)->update(['name'=>$data['name'],'description'=>$data['description']??'','status'=>$data['status'],'version'=>$row->version+1,'updated_at'=>now()]);
            $this->access->event($actor,$commission,'project.updated','project',$project,['status'=>$data['status'],'version'=>$row->version+1]);
            return ['id'=>$project,'version'=>$row->version+1];
        }));
    }

    public function tasks(string $commission,string $project,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission);$this->project($commission,$project);
        $request->validate(['page'=>['sometimes','integer','min:1']]);
        return response()->json(DB::table('cchia_workspace.tasks as t')->leftJoin('public.users as u','u.id','=','t.assignee_user_id')
            ->where('t.commission_id',$commission)->where('t.project_id',$project)->orderBy('t.created_at')->orderBy('t.id')
            ->paginate(30,['t.id','t.title','t.description','t.status','t.priority','t.assignee_user_id','u.name as assignee_name','t.due_date','t.version']))->header('Cache-Control','no-store');
    }

    private function project(string $commission,string $id,bool $write=false,bool $lock=false): object
    {
        $query=DB::table('cchia_workspace.projects')->where('commission_id',$commission)->where('id',$id);
        if($lock)$query->lockForUpdate();$row=$query->first();abort_unless($row,404);
        if($write)abort_unless($row->status==='active',409,'El proyecto está archivado.');return $row;
    }

    private function taskData(Request $request,bool $create): array
    {
        $this->validateText($request,['title','description']);
        return $request->validate(['title'=>[$create?'required':'sometimes','string','max:160','not_regex:/[\x00-\x1F]/'],
            'description'=>[$create?'present':'sometimes','nullable','string','max:3000','not_regex:/\x00/'],
            'status'=>['sometimes','in:todo,in_progress,done'],'priority'=>['sometimes','in:low,normal,high'],
            'assignee_user_id'=>['sometimes','nullable','uuid'],'due_date'=>['sometimes','nullable','date_format:Y-m-d'],
            'version'=>[$create?'prohibited':'required','integer','min:1']]);
    }

    private function assignee(object $commission,?string $user): void
    {
        if($user!==null&&!$this->access->assignments($commission->scope_id,$user)->exists())throw ValidationException::withMessages(['assignee_user_id'=>'El responsable debe tener una asignación vigente en esta comisión.']);
    }

    public function createTask(string $commission,string $project,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission,true);abort_unless($this->access->manage($request->user(),$c),403);$data=$this->taskData($request,true);$actor=$request->user();
        return $this->json($this->mutation->run($actor,'task.create',$this->mutation->key($request),[$commission,$project,$data],function()use($actor,$commission,$project,$data){
            $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->manage($actor,$c),403);$this->project($commission,$project,true,true);
            $this->assignee($c,$data['assignee_user_id']??null);$id=(string)Str::uuid();
            DB::table('cchia_workspace.tasks')->insert(['id'=>$id,'commission_id'=>$commission,'project_id'=>$project,'title'=>$data['title'],'description'=>$data['description']??'',
                'status'=>$data['status']??'todo','priority'=>$data['priority']??'normal','assignee_user_id'=>$data['assignee_user_id']??null,'due_date'=>$data['due_date']??null,'created_by_user_id'=>$actor->id]);
            $this->access->event($actor,$commission,'task.created','task',$id,['project_id'=>$project]);return ['id'=>$id,'version'=>1];
        }));
    }

    public function updateTask(string $commission,string $project,string $task,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission,true);$data=$this->taskData($request,false);$actor=$request->user();
        return $this->json($this->mutation->run($actor,'task.update',$this->mutation->key($request),[$commission,$project,$task,$data],function()use($actor,$commission,$project,$task,$data,$request){
            $c=$this->access->commission($actor,$commission,true);$this->project($commission,$project,true,true);
            $row=DB::table('cchia_workspace.tasks')->where('commission_id',$commission)->where('project_id',$project)->where('id',$task)->lockForUpdate()->first();abort_unless($row,404);
            if(!$this->access->manage($actor,$c))abort_unless($row->assignee_user_id===$actor->id && isset($data['status'])
                && array_diff(array_keys($request->all()),['status','version'])===[],403);
            abort_unless((int)$row->version===(int)$data['version'],409,'La tarea cambió. Recarga su estado.');
            if(array_key_exists('assignee_user_id',$data))$this->assignee($c,$data['assignee_user_id']);
            $changes=array_diff_key($data,['version'=>true]);$changes['version']=$row->version+1;$changes['updated_at']=now();
            if(array_key_exists('description',$changes))$changes['description']??='';
            DB::table('cchia_workspace.tasks')->where('id',$task)->update($changes);
            $this->access->event($actor,$commission,'task.updated','task',$task,['status'=>$changes['status']??$row->status,'version'=>$changes['version']]);
            return ['id'=>$task,'version'=>$changes['version']];
        }));
    }

    public function revokeMember(string $commission,string $assignment,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission,true);abort_unless($this->access->manage($request->user(),$c),403);
        $this->validateText($request,['reason']);$data=$request->validate(['reason'=>['required','string','min:10','max:500','not_regex:/\x00/'],'confirmation'=>['required','accepted']]);$actor=$request->user();
        return $this->json($this->mutation->run($actor,'member.revoke',$this->mutation->key($request),[$commission,$assignment,$data],function()use($actor,$commission,$assignment,$data){
            $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->manage($actor,$c),403);
            $row=DB::table('cchia_m02.role_assignments')->where('scope_id',$c->scope_id)->where('id',$assignment)->whereIn('role_code',['lider_comision','miembro_comision'])->lockForUpdate()->first();abort_unless($row,404);
            abort_unless($row->role_code==='miembro_comision'||$this->access->administrator($actor),403);
            if($row->status!=='revoked')DB::table('cchia_m02.role_assignments')->where('id',$assignment)->update(['status'=>'revoked','revoked_at'=>now(),'revocation_reason'=>$data['reason']]);
            $this->access->event($actor,$commission,'member.revoked','assignment',$assignment,['role_code'=>$row->role_code]);return ['id'=>$assignment,'status'=>'revoked'];
        }));
    }

    public function resources(string $commission,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission);$request->validate(['page'=>['sometimes','integer','min:1']]);
        return response()->json(DB::table('cchia_workspace.resources')->where('commission_id',$commission)->orderByDesc('created_at')->orderBy('id')
            ->paginate(20,['id','title','original_name','mime_type','byte_size','created_at']))->header('Cache-Control','no-store');
    }

    public function uploadResource(string $commission,Request $request): JsonResponse
    {
        $c=$this->access->commission($request->user(),$commission,true);abort_unless($this->access->manage($request->user(),$c),403);$this->validateText($request,['title']);
        $data=$request->validate(['title'=>['required','string','max:160','not_regex:/[\x00-\x1F]/'],'file'=>['required','file','max:5120','mimes:pdf,jpg,jpeg,png']]);
        $file=$data['file'];$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());$ext=strtolower($file->getClientOriginalExtension());
        $allowed=['application/pdf'=>['pdf'],'image/jpeg'=>['jpg','jpeg'],'image/png'=>['png']];
        if($file->getSize()<1||!isset($allowed[$mime])||!in_array($ext,$allowed[$mime],true))throw ValidationException::withMessages(['file'=>'El archivo debe tener contenido y coincidir con su extensión.']);
        $name=mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u','_',mb_convert_encoding($file->getClientOriginalName(),'UTF-8','UTF-8')),0,255);
        $hash=hash_file('sha256',$file->getRealPath());$actor=$request->user();$stored=null;
        try{
            $result=$this->mutation->run($actor,'resource.upload',$this->mutation->key($request),[$commission,$data['title'],$name,$mime,$file->getSize(),$hash],function()use($actor,$commission,$data,$file,$mime,$name,$hash,&$stored){
                $c=$this->access->commission($actor,$commission,true);abort_unless($this->access->manage($actor,$c),403);
                $id=(string)Str::uuid();$extension=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime];$stored=$commission.'/'.$id.'.'.$extension;
                if(!Storage::disk(config('workspace.disk'))->put($stored,file_get_contents($file->getRealPath()),['visibility'=>'private']))throw new \RuntimeException('workspace_storage_failed');
                DB::table('cchia_workspace.resources')->insert(['id'=>$id,'commission_id'=>$commission,'title'=>$data['title'],'original_name'=>$name,'storage_key'=>$stored,
                    'mime_type'=>$mime,'byte_size'=>$file->getSize(),'sha256'=>$hash,'uploaded_by_user_id'=>$actor->id]);
                $this->access->event($actor,$commission,'resource.uploaded','resource',$id);return ['id'=>$id];
            });
            return $this->json($result);
        }catch(\Throwable $error){if($stored!==null)try{Storage::disk(config('workspace.disk'))->delete($stored);}catch(\Throwable){}throw $error;}
    }

    public function download(string $commission,string $resource,Request $request)
    {
        $this->access->commission($request->user(),$commission);
        $row=DB::table('cchia_workspace.resources')->where('commission_id',$commission)->where('id',$resource)->first();abort_unless($row,404);
        $disk=Storage::disk(config('workspace.disk'));abort_unless($disk->exists($row->storage_key),404);
        $this->access->event($request->user(),$commission,'resource.download_started','resource',$resource);
        return $disk->download($row->storage_key,$row->original_name,['Content-Type'=>$row->mime_type,'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox"]);
    }

    public function events(string $commission,Request $request): JsonResponse
    {
        $this->access->commission($request->user(),$commission);$request->validate(['page'=>['sometimes','integer','min:1']]);
        return response()->json(DB::table('cchia_workspace.events as e')->leftJoin('public.users as u','u.id','=','e.actor_user_id')->where('e.commission_id',$commission)
            ->orderByDesc('e.occurred_at')->orderByDesc('e.id')->paginate(25,['e.id','e.action','e.entity_type','e.entity_id','e.occurred_at','u.name as actor_name']))->header('Cache-Control','no-store');
    }
}
