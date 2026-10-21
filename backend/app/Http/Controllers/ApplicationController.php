<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Models\AdmissionApplication;
use App\Services\RegisterApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    public function store(StoreApplicationRequest $request, RegisterApplication $service): JsonResponse
    {
        $data = $request->validated();
        $documents = $request->file('documents', []);
        unset($data['documents']);
        $result = $service->register($data, $documents);
        $application = $result['application'];

        return response()->json(['data' => [
            'id' => $application->id, 'reference' => $application->reference, 'status' => $application->status,
            'submitted_at' => $application->submitted_at->toIso8601String(),
            'tracking_token' => $result['tracking_token'],
        ], 'message' => 'Postulación recibida. Conserva el folio y el código de seguimiento; no otorga membresía automática.'], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function show(AdmissionApplication $application): JsonResponse
    {
        Gate::authorize('view', $application);
        $application->load(['documents', 'sponsors', 'events']);

        return response()->json(['data' => $application])->header('Cache-Control', 'no-store');
    }
}
