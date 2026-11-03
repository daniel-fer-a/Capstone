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
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AdmissionApplication::query()->select(['id', 'reference', 'membership', 'legal', 'status', 'submitted_at', 'rules_version']);
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['reference'])) {
            $query->where('reference', $data['reference']);
        }

        return response()->json($query->orderBy('submitted_at')->orderBy('id')->paginate(20))->header('Cache-Control', 'no-store');
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

        return response()->json(['data' => $application])->header('Cache-Control', 'no-store');
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
            $resolution = $locked->resolution()->create([...$data, 'recorded_by_user_id' => $request->user()->id, 'recorded_at' => now()]);
            $locked->update(['status' => $data['outcome'], 'decision_reason' => $data['reason'],
                'decision_at' => $resolution->recorded_at, 'decision_by_user_id' => $request->user()->id]);
            $locked->events()->create(['actor_user_id' => $request->user()->id, 'actor_kind' => 'institutional',
                'action' => 'application.decision_recorded', 'outcome' => 'success',
                'metadata' => ['status' => $data['outcome'], 'resolution_id' => $resolution->id], 'occurred_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            return $resolution;
        });
        return response()->json(['data' => $resolution])->header('Cache-Control', 'no-store');
    }
}
