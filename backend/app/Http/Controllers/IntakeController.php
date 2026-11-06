<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntakeRequest;
use App\Services\PrivateSponsorships;
use App\Services\RegisterApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntakeController extends Controller
{
    public function store(StoreIntakeRequest $request, PrivateSponsorships $service): JsonResponse
    {
        $result = $service->create($request->validated(), $request->header('Idempotency-Key'));
        return response()->json(['data' => [...$service->ownerData($result['intake']),
            'access_token' => $result['access_token'], 'replayed' => $result['replayed']]], 201)->header('Cache-Control', 'no-store');
    }

    public function resume(Request $request, PrivateSponsorships $service): JsonResponse
    {
        $data = $this->credentials($request);
        $intake = $service->authorizeOwner($data['reference'], $data['access_token']);
        return response()->json(['data' => $service->ownerData($intake)])->header('Cache-Control', 'no-store');
    }

    public function replace(Request $request, PrivateSponsorships $service): JsonResponse
    {
        abort_unless(config('admissions.sponsorships_enabled'), 503);
        $credentials = $this->credentials($request);
        if (is_array($request->input('sponsorEmails'))) {
            $request->merge(['sponsorEmails' => array_map(fn ($email) => is_string($email) ? mb_strtolower(trim($email)) : $email, $request->input('sponsorEmails'))]);
        }
        $data = $request->validate([
            'sponsorEmails' => ['required', 'array', 'list', 'size:2'],
            'sponsorEmails.*' => ['bail', 'required', 'string', 'email:rfc', 'max:255'],
            'confirmation' => ['required', 'accepted'],
        ]);
        if ($data['sponsorEmails'][0] === $data['sponsorEmails'][1]) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sponsorEmails' => 'Los dos socios deben ser distintos.']);
        }
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $key), 422, 'Se requiere un identificador de operación válido.');
        $intake = DB::transaction(function () use ($credentials, $service, $data, $key) {
            $intake = $service->authorizeOwner($credentials['reference'], $credentials['access_token'], true);
            foreach ($data['sponsorEmails'] as $email) {
                abort_if($email === $service->payload($intake)['email'], 422, 'Los patrocinadores deben ser distintos de quien presenta la solicitud.');
            }
            $service->replace($intake, $data['sponsorEmails'], $key);
            return $intake;
        });
        return response()->json(['data' => $service->ownerData($intake)])->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request, PrivateSponsorships $service, RegisterApplication $register): JsonResponse
    {
        abort_unless(config('admissions.sponsorships_enabled'), 503);
        $credentials = $this->credentials($request);
        $result = DB::transaction(function () use ($request, $credentials, $service, $register): array {
            $intake = $service->authorizeOwner($credentials['reference'], $credentials['access_token'], true);
            $existing = $intake->application()->first();
            $sponsors = $existing ? [] : $service->confirmedSponsors($intake);
            $juridica = $intake->legal === 'juridica';
            $file = ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.config('admissions.max_file_kb')];
            $request->validate([
                'declaration_accepted' => ['required', 'accepted'],
                'documents' => ['sometimes', $juridica ? 'array:estatutos,vigencia' : 'array:antecedentes'],
                'documents.estatutos' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), ...$file],
                'documents.vigencia' => [Rule::requiredIf($juridica), Rule::prohibitedIf(!$juridica), ...$file],
                'documents.antecedentes' => [Rule::prohibitedIf($juridica), ...$file],
                'documents.personeria' => ['prohibited'],
                'payload' => ['prohibited'], 'sponsors' => ['prohibited'], 'status' => ['prohibited'],
            ]);
            $data = $service->payload($intake);
            unset($data['sharing_accepted']);
            $result = $register->register($data, $request->file('documents', []), hash('sha256', 'intake:'.$intake->id), [
                'intake_id' => $intake->id, 'sponsors' => $sponsors,
            ]);
            return $result;
        });
        $application = $result['application'];
        return response()->json(['data' => [
            'id' => $application->id, 'reference' => $application->reference, 'status' => $application->status,
            'submitted_at' => $application->submitted_at->toIso8601String(),
            'tracking_token' => $result['tracking_token'], 'replayed' => $result['replayed'], 'local_pilot' => false,
        ], 'message' => 'Postulación recibida para revisión. La recepción no otorga membresía.'], 201)->header('Cache-Control', 'no-store');
    }

    private function credentials(Request $request): array
    {
        abort_if($request->query->has('access_token') || $request->query->has('reference'), 422, 'Envía el folio y el código en el cuerpo de la solicitud.');
        return $request->validate([
            'reference' => ['required', 'string', 'max:40', 'regex:/^CCHIA-PRE-[A-Z0-9]{26}$/D'],
            'access_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]{64}$/D'],
        ]);
    }
}
