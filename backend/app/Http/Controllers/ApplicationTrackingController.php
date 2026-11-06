<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationTrackingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40', 'regex:/^CCHIA-[A-Z0-9-]+$/D'],
            'tracking_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D'],
        ]);
        $application = AdmissionApplication::query()->where('reference', $data['reference'])->first();
        abort_unless($application && hash_equals($application->tracking_token_hash, hash('sha256', $data['tracking_token'])), 404, 'No encontramos una solicitud con esos datos.');
        $labels = [
            'application.submitted' => 'Solicitud recibida',
            'application.review_started' => 'Revisión iniciada por el Directorio',
            'application.decision_recorded' => 'Resolución del Directorio registrada',
        ];
        $history = $application->events()->whereIn('action', array_keys($labels))->get()
            ->map(fn ($event) => ['action' => $event->action, 'label' => $labels[$event->action], 'occurred_at' => $event->occurred_at->toIso8601String()]);
        $resolution = $application->resolution()->first();

        return response()->json(['data' => [
            'reference' => $application->reference,
            'status' => $application->status,
            'submitted_at' => $application->submitted_at->toIso8601String(),
            'decision_at' => $application->decision_at?->toIso8601String(),
            'history' => $history,
            'decision' => $resolution ? ['outcome' => $resolution->outcome, 'reason' => $resolution->reason,
                'meeting_date' => $resolution->meeting_date->toDateString()] : null,
        ]])->header('Cache-Control', 'no-store');
    }
}
