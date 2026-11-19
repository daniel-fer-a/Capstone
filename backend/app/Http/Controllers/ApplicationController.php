<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Models\AdmissionApplication;
use App\Services\RegisterApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function store(StoreApplicationRequest $request, RegisterApplication $service): JsonResponse
    {
        $data = $request->validated();
        $documents = $request->file('documents', []);
        unset($data['documents']);
        $result = $service->register($data, $documents, $request->header('Idempotency-Key'));
        $application = $result['application'];

        return response()->json(['data' => [
            'id' => $application->id, 'reference' => $application->reference, 'status' => $application->status,
            'submitted_at' => $application->submitted_at->toIso8601String(),
            'tracking_token' => $result['tracking_token'], 'replayed' => $result['replayed'],
            'local_pilot' => $application->rules_version === config('admissions.pilot_rules_version'),
        ], 'message' => 'Postulación recibida. Conserva el folio y el código de seguimiento; no otorga membresía automática.'], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AdmissionApplication::class);
        $data = $request->validate([
            'status' => ['sometimes', 'in:received,under_review,approved,rejected'],
            'reference' => ['sometimes', 'string', 'max:40'],
            'task' => ['sometimes', 'in:pending_assembly,waiting_applicant,documents_submitted,approved_unregistered'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AdmissionApplication::query()->select(['id', 'reference', 'membership', 'legal', 'status', 'submitted_at', 'rules_version']);
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['reference'])) {
            $query->where('reference', $data['reference']);
        }

        if (!empty($data['task'])) {
            if ($data['task'] === 'pending_assembly') $query->whereExists(fn ($q) => $q->selectRaw('1')->from('cchia_admissions.appeals as p')
                ->whereColumn('p.application_id', 'applications.id')->whereNotExists(fn ($r) => $r->selectRaw('1')->from('cchia_admissions.assembly_resolutions as a')->whereColumn('a.appeal_id', 'p.id')));
            if (in_array($data['task'], ['waiting_applicant','documents_submitted'], true)) $query->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('cchia_admissions.correction_requests as r')->whereColumn('r.application_id', 'applications.id')
                    ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('cchia_admissions.correction_reviews as v')->whereColumn('v.correction_id', 'r.id'));
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('cchia_admissions.correction_submissions as s')->whereColumn('s.correction_id', 'r.id'), 'and', $data['task'] === 'waiting_applicant');
            });
            if ($data['task'] === 'approved_unregistered') $query->where('membership','member')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cchia_admissions.membership_registrations as m')->whereColumn('m.application_id','applications.id'))
                ->where(function ($q) {
                    $q->where('status','approved')->orWhereExists(fn ($r) => $r->selectRaw('1')->from('cchia_admissions.appeals as p')->join('cchia_admissions.assembly_resolutions as a','a.appeal_id','=','p.id')->whereColumn('p.application_id','applications.id')->where('a.outcome','approved'));
                });
        }

        $page = $query->orderBy('submitted_at')->orderBy('id')->paginate(20);
        $ids = $page->getCollection()->modelKeys();
        $registered = DB::table('cchia_admissions.membership_registrations')->whereIn('application_id', $ids)->pluck('application_id')->flip();
        $appeals = DB::table('cchia_admissions.appeals as p')->leftJoin('cchia_admissions.assembly_resolutions as r','r.appeal_id','=','p.id')
            ->whereIn('p.application_id',$ids)->get(['p.application_id','r.outcome'])->keyBy('application_id');
        $corrections = DB::table('cchia_admissions.correction_requests as r')->leftJoin('cchia_admissions.correction_submissions as s','s.correction_id','=','r.id')
            ->leftJoin('cchia_admissions.correction_reviews as v','v.correction_id','=','r.id')->whereIn('r.application_id',$ids)->whereNull('v.id')
            ->get(['r.application_id','s.id as submission_id'])->keyBy('application_id');
        $page->getCollection()->each(function ($app) use ($registered,$appeals,$corrections): void {
            $app->workflow = $registered->has($app->id) ? 'membership_registered'
                : ($appeals->has($app->id) ? ($appeals[$app->id]->outcome ? 'assembly_'.$appeals[$app->id]->outcome : 'pending_assembly')
                : ($corrections->has($app->id) ? ($corrections[$app->id]->submission_id ? 'documents_submitted' : 'waiting_applicant')
                : ($app->status === 'approved' ? 'approved_unregistered' : $app->status)));
        });
        return response()->json($page)->header('Cache-Control', 'no-store');
    }

    public function startReview(AdmissionApplication $application, Request $request): JsonResponse
    {
        Gate::authorize('startReview', $application);
        $application = DB::transaction(function () use ($application, $request) {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless(in_array($locked->status, ['received', 'under_review'], true), 409, 'La solicitud ya tiene una resolución y no puede reiniciarse.');
            if ($locked->status === 'received') {
                $locked->update(['status' => 'under_review']);
                $locked->events()->create([
                    'actor_user_id' => $request->user()->id,
                    'actor_kind' => 'institutional', 'action' => 'application.review_started',
                    'outcome' => 'success', 'metadata' => ['status' => 'under_review'], 'occurred_at' => now(),
                ]);
            }

            return $locked;
        });

        return response()->json(['data' => ['id' => $application->id, 'status' => $application->status]])->header('Cache-Control', 'no-store');
    }

    public function show(AdmissionApplication $application): JsonResponse
    {
        Gate::authorize('view', $application);
        $application->load(['documents', 'sponsors.user:id,name', 'events.actor:id,name', 'resolution']);

        return response()->json(['data' => [...$application->toArray(), 'casework' => app(\App\Services\AdmissionCasework::class)->summary($application, true)]])->header('Cache-Control', 'no-store');
    }

    public function recordDecision(AdmissionApplication $application, Request $request): JsonResponse
    {
        Gate::authorize('recordDecision', $application);
        foreach (['reason', 'minutes_reference'] as $field) {
            if (is_string($request->input($field))) $request->merge([$field => trim($request->input($field))]);
        }
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'max:10000', 'not_regex:/\\x00/'],
            'minutes_reference' => ['required', 'string', 'max:255', 'not_regex:/\\x00/'],
            'meeting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Santiago')->toDateString(),
                'after_or_equal:'.$application->submitted_at->setTimezone('America/Santiago')->toDateString()],
            'actor_user_id' => ['prohibited'], 'decision_at' => ['prohibited'], 'roles' => ['prohibited'],
        ]);
        $resolution = DB::transaction(function () use ($application, $request, $data) {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_if($locked->rules_version === config('admissions.pilot_rules_version'), 409, 'Una prueba local no recibe una resolución institucional.');
            $previous = $locked->resolution()->first();
            if ($previous) {
                abort_unless($previous->outcome === $data['outcome'] && $previous->reason === $data['reason']
                    && $previous->minutes_reference === $data['minutes_reference'] && $previous->meeting_date->toDateString() === $data['meeting_date']
                    && $previous->recorded_by_user_id === $request->user()->id, 409, 'La resolución inicial ya está registrada y no se sobrescribe.');
                return $previous;
            }
            abort_unless($locked->status === 'under_review', 409, 'Inicia la revisión antes de registrar el acuerdo del Directorio.');
            $casework = app(\App\Services\AdmissionCasework::class);
            abort_if($casework->openCorrection($locked->id), 409, 'Termina la subsanación pendiente antes de resolver.');
            $lastReview = DB::table('cchia_admissions.correction_reviews as v')->join('cchia_admissions.correction_requests as r', 'r.id', '=', 'v.correction_id')->where('r.application_id', $locked->id)->orderByDesc('r.sequence_no')->value('v.outcome');
            abort_if($data['outcome'] === 'approved' && $lastReview === 'needs_changes', 409, 'Los documentos observados requieren una nueva subsanación aceptada antes de aprobar.');
            $resolution = $locked->resolution()->create([...$data, 'recorded_by_user_id' => $request->user()->id, 'recorded_at' => now()]);
            $locked->update(['status' => $data['outcome'], 'decision_reason' => $data['reason'],
                'decision_at' => $resolution->recorded_at, 'decision_by_user_id' => $request->user()->id]);
            $event = $locked->events()->create(['actor_user_id' => $request->user()->id, 'actor_kind' => 'institutional',
                'action' => 'application.decision_recorded', 'outcome' => 'success',
                'metadata' => ['status' => $data['outcome'], 'resolution_id' => $resolution->id], 'occurred_at' => now()]);
            \App\Services\NotificationOutbox::enqueueEvent($locked->id, $event->id);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            return $resolution;
        });
        return response()->json(['data' => $resolution])->header('Cache-Control', 'no-store');
    }
}
