<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Models\AdmissionApplication;
use App\Services\RegisterApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        $application->load(['documents', 'sponsors', 'events.actor:id,name']);

        return response()->json(['data' => $application])->header('Cache-Control', 'no-store');
    }
}
