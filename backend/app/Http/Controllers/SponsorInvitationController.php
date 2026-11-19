<?php

namespace App\Http\Controllers;

use App\Models\AdmissionIntake;
use App\Models\SponsorInvitation;
use App\Services\PrivateSponsorships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SponsorInvitationController extends Controller
{
    public function index(Request $request, PrivateSponsorships $service): JsonResponse
    {
        abort_unless($service->activeAssignment($request->user()->id), 403);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $invitations = SponsorInvitation::query()->with('intake')
            ->where('target_user_id', $request->user()->id)->where('email', mb_strtolower($request->user()->email))
            ->whereNull('superseded_at')->orderByDesc('created_at')->orderBy('id')->paginate(20);
        $invitations->through(function ($invitation) use ($service): array {
            $data = $service->payload($invitation->intake);
            return ['id' => $invitation->id, 'legal' => $invitation->intake->legal,
                'applicant_name' => $data['name'] ?? $data['organizationName'],
                'ai_activity' => $data['aiActivity'], 'region' => $data['region'], 'commune' => $data['commune'],
                'state' => $invitation->state, 'created_at' => $invitation->created_at->toIso8601String(),
                'decided_at' => $invitation->decided_at?->toIso8601String(),
                'submitted' => $invitation->intake->application()->exists()];
        });
        return response()->json($invitations)->header('Cache-Control', 'no-store');
    }

    public function decide(SponsorInvitation $invitation, Request $request, PrivateSponsorships $service): JsonResponse
    {
        abort_unless(config('admissions.sponsorships_enabled'), 503);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'confirmation' => $request->input('decision') === 'accepted' ? ['required', 'accepted'] : ['sometimes', 'accepted'],
        ]);
        DB::transaction(function () use ($invitation, $request, $service, $data): void {
            AdmissionIntake::query()->whereKey($invitation->intake_id)->lockForUpdate()->firstOrFail();
            $locked = SponsorInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $service->decide($locked, $request->user(), $data['decision']);
        });
        return response()->json(['data' => ['id' => $invitation->id, 'state' => $data['decision']]])->header('Cache-Control', 'no-store');
    }
}
