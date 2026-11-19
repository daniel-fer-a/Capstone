<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Services\AdmissionCasework;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionCaseworkController extends Controller
{
    public function __construct(private readonly AdmissionCasework $service) {}

    public function appeal(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);
        $this->trim($request, ['reason']);
        $data = $request->validate(['reason' => $this->text(), 'confirmation' => ['required', 'accepted']]);
        $application = $this->service->owner($credentials);
        abort_unless(config('admissions.review_enabled'), 503);
        $this->service->appeal($application, $data['reason']);
        return $this->response($this->service->summary($application));
    }

    public function assembly(AdmissionApplication $application, Request $request): JsonResponse
    {
        Gate::authorize('recordAppeal', $application);
        $this->trim($request, ['reason', 'minutes_reference']);
        $data = $request->validate(['outcome' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => $this->text(),
            'minutes_reference' => $this->text(255), 'meeting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Santiago')->toDateString()],
            'meeting_type' => ['required', Rule::in(['ordinary', 'extraordinary'])], 'confirmation' => ['required', 'accepted'],
            'recorded_by_user_id' => ['prohibited'], 'recorded_at' => ['prohibited']]);
        unset($data['confirmation']);
        $this->service->assembly($application, $data, $request->user()->id);
        return $this->response($this->service->summary($application, true));
    }

    public function requestCorrection(AdmissionApplication $application, Request $request): JsonResponse
    {
        Gate::authorize('manageCorrections', $application);
        $this->trim($request, ['reason']);
        $request->merge(['operation_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate(['reason' => $this->text(), 'requested_kinds' => ['required', 'array', 'min:1', 'max:3'],
            'requested_kinds.*' => ['bail', 'string', Rule::in(['estatutos', 'vigencia', 'personeria', 'antecedentes']), 'distinct:strict'],
            'operation_key' => ['required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/D']]);
        $row = $this->service->requestCorrection($application, $data, $request->user()->id, $data['operation_key']);
        return $this->response(['id' => $row->id]);
    }

    public function submitCorrection(Request $request): JsonResponse
    {
        $application = $this->service->owner($this->credentials($request));
        abort_unless(config('admissions.review_enabled'), 503);
        $data = $request->validate(['correction_id' => ['required', 'uuid'], 'confirmation' => ['required', 'accepted'],
            'documents' => ['required', 'array:estatutos,vigencia,personeria,antecedentes'],
            'documents.*' => ['required', new \App\Rules\AdmissionDocument(), 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'min:0.001', 'max:'.config('admissions.max_file_kb')]]);
        $this->service->submitCorrection($application, $data['correction_id'], $request->file('documents', []));
        return $this->response($this->service->summary($application));
    }

    public function reviewCorrection(AdmissionApplication $application, string $correction, Request $request): JsonResponse
    {
        Gate::authorize('manageCorrections', $application);
        $this->trim($request, ['reason']);
        $data = $request->validate(['outcome' => ['required', Rule::in(['accepted', 'needs_changes'])], 'reason' => $this->text(), 'confirmation' => ['required', 'accepted']]);
        unset($data['confirmation']);
        $this->service->reviewCorrection($application, $correction, $data, $request->user()->id);
        return $this->response($this->service->summary($application, true));
    }

    public function formalize(AdmissionApplication $application, Request $request): JsonResponse
    {
        Gate::authorize('formalize', $application);
        $this->trim($request, ['register_reference']);
        $data = $request->validate(['register_reference' => $this->text(255), 'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Santiago')->toDateString()],
            'confirmation' => ['required', 'accepted'], 'user_id' => ['prohibited'], 'roles' => ['prohibited']]);
        unset($data['confirmation']);
        $this->service->formalize($application, $data, $request->user()->id);
        return $this->response($this->service->summary($application, true));
    }

    public function downloadRevision(AdmissionApplication $application, string $revision): StreamedResponse
    {
        Gate::authorize('downloadDocument', $application);
        $row = DB::table('cchia_admissions.document_revisions')->where('application_id', $application->id)->find($revision);
        abort_unless($row, 404);
        $disk = Storage::disk($row->disk);
        abort_unless($disk->exists($row->storage_key), 404);
        return $disk->download($row->storage_key, $row->original_name, ['Content-Type' => $row->mime_type,
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function credentials(Request $request): array
    {
        abort_if($request->query->has('reference') || $request->query->has('tracking_token'), 422, 'Usa el cuerpo de la solicitud para las credenciales privadas.');
        return $request->validate(['reference' => ['required', 'string', 'max:40', 'regex:/^CCHIA-[A-Z0-9-]+$/D'],
            'tracking_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D']]);
    }

    private function text(int $max = 10000): array { return ['required', 'string', 'max:'.$max, 'not_regex:/\\x00/']; }
    private function trim(Request $request, array $fields): void
    {
        foreach ($fields as $field) if (is_string($request->input($field))) $request->merge([$field => trim($request->input($field))]);
    }
    private function response(array $data): JsonResponse { return response()->json(['data' => $data])->header('Cache-Control', 'no-store'); }
}
