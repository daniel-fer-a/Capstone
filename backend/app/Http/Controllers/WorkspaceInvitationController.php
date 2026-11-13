<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceAccess;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceInvitationController extends Controller
{
    public function __construct(private readonly WorkspaceInvitations $invitations) {}
    private function json(array $data){return response()->json(['data'=>$data])->header('Cache-Control','no-store');}
    public function issue(string $commission,Request $request){return $this->json($this->invitations->issue($commission,$request));}
    public function preview(Request $request){return $this->json($this->invitations->preview($request));}
    public function accept(Request $request){return $this->json($this->invitations->accept($request));}
    public function decline(Request $request){return $this->json($this->invitations->decline($request));}
    public function cancel(string $commission,string $invitation,Request $request){return $this->json($this->invitations->cancel($commission,$invitation,$request));}
    public function index(string $commission,Request $request)
    {
        $access=app(WorkspaceAccess::class);$c=$access->commission($request->user(),$commission);
        abort_unless($access->invite($request->user(),$c,'miembro_comision'),403);$request->validate(['page'=>['sometimes','integer','min:1']]);
        return response()->json(DB::table('cchia_m02.role_invitations as i')->join('cchia_workspace.invitation_details as d','d.invitation_id','=','i.id')
            ->where('d.commission_id',$commission)->orderByDesc('i.created_at')->orderBy('i.id')
            ->paginate(20,['i.id','i.invitee_email','i.role_code','i.status','i.created_at','i.expires_at','i.accepted_at','d.assignment_ends_at','d.appointment_reference']))->header('Cache-Control','no-store');
    }
}
