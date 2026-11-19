<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdmissionCasework
{
    public function owner(array $data): AdmissionApplication
    {
        $application = AdmissionApplication::query()->where('reference', $data['reference'])->first();
        abort_unless($application && hash_equals($application->tracking_token_hash, hash('sha256', $data['tracking_token'])), 404, 'No encontramos una solicitud con esos datos.');
        return $application;
    }

    public function event(AdmissionApplication $application, string $action, ?string $actorId = null, array $metadata = []): void
    {
        $event = $application->events()->create(['actor_user_id' => $actorId,
            'actor_kind' => $actorId ? 'institutional' : 'applicant', 'action' => $action,
            'outcome' => 'success', 'metadata' => $metadata, 'occurred_at' => now()]);
        NotificationOutbox::enqueueEvent($application->id, $event->id);
    }

    public function appeal(AdmissionApplication $application, string $reason): object
    {
        return DB::transaction(function () use ($application, $reason) {
            $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $previous = DB::table('cchia_admissions.appeals')->where('application_id', $app->id)->first();
            $hash = hash('sha256', $reason);
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409, 'La apelación de este expediente ya fue presentada; no se sobrescribe.');
                return $previous;
            }
            abort_unless($app->status === 'rejected' && $app->resolution()->where('outcome', 'rejected')->exists(), 409, 'La apelación requiere una resolución inicial de rechazo.');
            $id = (string) Str::uuid();
            DB::table('cchia_admissions.appeals')->insert(['id' => $id, 'application_id' => $app->id,
                'reason' => $reason, 'requested_at' => now(), 'request_hash' => $hash]);
            $this->event($app, 'application.appeal_requested');
            return DB::table('cchia_admissions.appeals')->find($id);
        });
    }

    public function assembly(AdmissionApplication $application, array $data, string $actorId): object
    {
        return DB::transaction(function () use ($application, $data, $actorId) {
            $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $appeal = DB::table('cchia_admissions.appeals')->where('application_id', $app->id)->first();
            abort_unless($appeal, 409, 'Debe existir una apelación solicitada ante el Directorio.');
            $previous = DB::table('cchia_admissions.assembly_resolutions')->where('appeal_id', $appeal->id)->first();
            if ($previous) {
                $this->same($previous, [...$data, 'recorded_by_user_id' => $actorId]);
                return $previous;
            }
            abort_if($data['meeting_date'] < \Carbon\CarbonImmutable::parse($appeal->requested_at)->setTimezone('America/Santiago')->toDateString(), 422, 'La sesión no puede ser anterior a la apelación.');
            $id = (string) Str::uuid();
            DB::table('cchia_admissions.assembly_resolutions')->insert(['id' => $id, 'appeal_id' => $appeal->id,
                ...$data, 'recorded_by_user_id' => $actorId, 'recorded_at' => now()]);
            $this->event($app, 'application.appeal_resolved', $actorId, ['outcome' => $data['outcome']]);
            return DB::table('cchia_admissions.assembly_resolutions')->find($id);
        });
    }

    public function requestCorrection(AdmissionApplication $application, array $data, string $actorId, string $key): object
    {
        return DB::transaction(function () use ($application, $data, $actorId, $key) {
            $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $hash = hash('sha256', $app->id.':'.$key);
            $previous = DB::table('cchia_admissions.correction_requests')->where('operation_hash', $hash)->first();
            sort($data['requested_kinds']);
            if ($previous) {
                $this->same($previous, ['reason' => $data['reason'], 'requested_by_user_id' => $actorId]);
                abort_unless(json_decode($previous->requested_kinds, true) === $data['requested_kinds'], 409, 'La clave corresponde a otra solicitud de documentos.');
                return $previous;
            }
            abort_unless($app->status === 'under_review', 409, 'Inicia la revisión antes de solicitar documentos.');
            abort_if($this->openCorrection($app->id), 409, 'Primero debe terminar la subsanación ya abierta.');
            $allowed = $app->legal === 'natural' ? ['antecedentes'] : ($app->rules_version === config('admissions.staged_rules_version') ? ['estatutos', 'vigencia'] : ['estatutos', 'vigencia', 'personeria']);
            if (array_diff($data['requested_kinds'], $allowed)) throw ValidationException::withMessages(['requested_kinds' => 'Solicita solo documentos de esta modalidad.']);
            $id = (string) Str::uuid();
            DB::table('cchia_admissions.correction_requests')->insert(['id' => $id, 'application_id' => $app->id,
                'reason' => $data['reason'], 'requested_kinds' => json_encode($data['requested_kinds'], JSON_THROW_ON_ERROR),
                'operation_hash' => $hash, 'requested_by_user_id' => $actorId, 'requested_at' => now()]);
            $this->event($app, 'application.correction_requested', $actorId, ['correction_id' => $id]);
            return DB::table('cchia_admissions.correction_requests')->find($id);
        });
    }

    public function submitCorrection(AdmissionApplication $application, string $correctionId, array $files): object
    {
        $disk = Storage::disk(config('admissions.disk'));
        $written = [];
        try {
            return DB::transaction(function () use ($application, $correctionId, $files, $disk, &$written) {
                $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
                $request = DB::table('cchia_admissions.correction_requests')->where('application_id', $app->id)->find($correctionId);
                abort_unless($request, 404);
                $kinds = json_decode($request->requested_kinds, true);
                $actual = array_keys($files); sort($actual); sort($kinds);
                if ($actual !== $kinds) throw ValidationException::withMessages(['documents' => 'Adjunta exactamente los documentos solicitados.']);
                $fingerprint = [];
                foreach ($files as $kind => $file) $fingerprint[$kind] = [hash_file('sha256', $file->getRealPath()), $file->getClientOriginalName()];
                ksort($fingerprint);
                $hash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
                $previous = DB::table('cchia_admissions.correction_submissions')->where('correction_id', $correctionId)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_hash, $hash), 409, 'Esta subsanación ya fue enviada con otros archivos.');
                    return $previous;
                }
                abort_unless($app->status === 'under_review', 409, 'La revisión de este expediente ya terminó.');
                $id = (string) Str::uuid();
                DB::table('cchia_admissions.correction_submissions')->insert(['id' => $id, 'correction_id' => $correctionId,
                    'request_hash' => $hash, 'submitted_at' => now()]);
                foreach ($files as $kind => $file) {
                    $mime = $file->getMimeType();
                    $extension = match ($mime) { 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => throw new \RuntimeException('Tipo de archivo no admitido.') };
                    $name = (string) Str::uuid().'.'.$extension;
                    $folder = $app->id.'/revisions';
                    $key = $folder.'/'.$name; $written[] = $key;
                    if ($disk->putFileAs($folder, $file, $name, ['visibility' => 'private']) === false) throw new \RuntimeException('No fue posible guardar la nueva versión.');
                    $version = max(1, (int) DB::table('cchia_admissions.document_revisions')->where('application_id', $app->id)->where('kind', $kind)->max('version_no')) + 1;
                    $original = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename($file->getClientOriginalName())) ?: 'documento.'.$extension;
                    DB::table('cchia_admissions.document_revisions')->insert(['id' => (string) Str::uuid(), 'submission_id' => $id,
                        'application_id' => $app->id, 'kind' => $kind, 'version_no' => $version, 'disk' => config('admissions.disk'),
                        'storage_key' => $key, 'original_name' => mb_substr($original, 0, 255), 'mime_type' => $mime,
                        'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_at' => now()]);
                }
                $this->event($app, 'application.correction_submitted', null, ['correction_id' => $correctionId]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); DB::statement('SET CONSTRAINTS ALL DEFERRED');
                return DB::table('cchia_admissions.correction_submissions')->find($id);
            });
        } catch (\Throwable $exception) {
            foreach ($written as $key) {
                try { $disk->delete($key); } catch (\Throwable) { Log::warning('admissions.orphan_revision_cleanup_required', ['storage_key' => $key]); }
            }
            throw $exception;
        }
    }

    public function reviewCorrection(AdmissionApplication $application, string $id, array $data, string $actorId): object
    {
        return DB::transaction(function () use ($application, $id, $data, $actorId) {
            $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless(DB::table('cchia_admissions.correction_requests')->where('application_id', $app->id)->where('id', $id)->exists(), 404);
            $previous = DB::table('cchia_admissions.correction_reviews')->where('correction_id', $id)->first();
            if ($previous) {
                $this->same($previous, [...$data, 'reviewed_by_user_id' => $actorId]);
                return $previous;
            }
            $submission = DB::table('cchia_admissions.correction_submissions')->where('correction_id', $id)->first();
            abort_unless($submission && $app->status === 'under_review', 409, 'La subsanación debe estar enviada y su revisión abierta.');
            $reviewId = (string) Str::uuid();
            DB::table('cchia_admissions.correction_reviews')->insert(['id' => $reviewId, 'correction_id' => $id,
                'submission_id' => $submission->id, ...$data, 'reviewed_by_user_id' => $actorId, 'reviewed_at' => now()]);
            $this->event($app, 'application.correction_reviewed', $actorId, ['correction_id' => $id, 'outcome' => $data['outcome']]);
            return DB::table('cchia_admissions.correction_reviews')->find($reviewId);
        });
    }

    public function formalize(AdmissionApplication $application, array $data, string $actorId): object
    {
        abort_unless(config('admissions.formalization_enabled'), 503, 'El registro de ingreso debe habilitarse expresamente.');
        return DB::transaction(function () use ($application, $data, $actorId) {
            $app = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $previous = DB::table('cchia_admissions.membership_registrations')->where('application_id', $app->id)->first();
            if ($previous) {
                $this->same($previous, [...$data, 'recorded_by_user_id' => $actorId]);
                return $previous;
            }
            $approved = $this->effectiveDecision($app);
            abort_unless($app->membership === 'member' && $approved && $approved->outcome === 'approved', 409, 'Se requiere aprobación institucional antes de registrar el ingreso.');
            abort_if($data['effective_date'] < substr((string) $approved->meeting_date, 0, 10), 422, 'La fecha de ingreso no puede ser anterior al acuerdo de aprobación.');
            $identifier = $app->legal === 'natural' ? $app->natural_identifier : $app->organization_identifier;
            $hash = hash('sha256', mb_strtoupper(preg_replace('/[^\p{L}\p{N}]/u', '', $identifier)));
            DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr($hash, 0, 15))]);
            abort_if(DB::table('cchia_admissions.membership_registrations')->where('legal', $app->legal)->where('identity_hash', $hash)->exists(), 409, 'Esta identidad ya tiene un ingreso registrado.');
            abort_if(DB::table('cchia_admissions.membership_registrations')->where('register_reference', $data['register_reference'])->exists(), 409, 'Esta referencia de registro ya está utilizada.');
            $id = (string) Str::uuid();
            DB::table('cchia_admissions.membership_registrations')->insert(['id' => $id, 'application_id' => $app->id,
                'legal' => $app->legal, 'identity_hash' => $hash, ...$data, 'recorded_by_user_id' => $actorId, 'recorded_at' => now()]);
            $this->event($app, 'application.membership_recorded', $actorId);
            return DB::table('cchia_admissions.membership_registrations')->find($id);
        });
    }

    public function effectiveDecision(AdmissionApplication $application): ?object
    {
        $appeal = DB::table('cchia_admissions.appeals')->where('application_id', $application->id)->first();
        if ($appeal) return DB::table('cchia_admissions.assembly_resolutions')->where('appeal_id', $appeal->id)->first();
        return $application->resolution()->first();
    }

    public function openCorrection(string $applicationId): ?object
    {
        return DB::table('cchia_admissions.correction_requests as r')->where('r.application_id', $applicationId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cchia_admissions.correction_reviews as v')->whereColumn('v.correction_id', 'r.id'))->first();
    }

    public function summary(AdmissionApplication $app, bool $institutional = false): array
    {
        $appeal = DB::table('cchia_admissions.appeals')->where('application_id', $app->id)->first();
        $assembly = $appeal ? DB::table('cchia_admissions.assembly_resolutions')->where('appeal_id', $appeal->id)->first() : null;
        $registration = DB::table('cchia_admissions.membership_registrations')->where('application_id', $app->id)->first();
        $corrections = DB::table('cchia_admissions.correction_requests')->where('application_id', $app->id)->orderBy('sequence_no')->get()->map(function ($r) use ($institutional) {
            $submission = DB::table('cchia_admissions.correction_submissions')->where('correction_id', $r->id)->first();
            $review = DB::table('cchia_admissions.correction_reviews')->where('correction_id', $r->id)->first();
            $files = $submission ? DB::table('cchia_admissions.document_revisions')->where('submission_id', $submission->id)
                ->orderBy('kind')->get(['id', 'kind', 'version_no', 'original_name', 'bytes', 'uploaded_at'])->all() : [];
            return ['id' => $r->id, 'reason' => $r->reason, 'requested_kinds' => json_decode($r->requested_kinds, true),
                'requested_at' => $r->requested_at, 'status' => $review ? $review->outcome : ($submission ? 'submitted' : 'waiting_applicant'),
                'submitted_at' => $submission?->submitted_at, 'documents' => $files,
                'review' => $review ? ['outcome' => $review->outcome, 'reason' => $review->reason, 'reviewed_at' => $review->reviewed_at,
                    ...($institutional ? ['reviewed_by_user_id' => $review->reviewed_by_user_id] : [])] : null];
        });
        return [
            'effective_outcome' => $assembly?->outcome ?? ($appeal ? null : ($app->resolution?->outcome)),
            'appeal' => $appeal ? ['id' => $appeal->id, 'reason' => $appeal->reason, 'requested_at' => $appeal->requested_at,
                'status' => $assembly ? 'resolved' : 'pending_assembly', 'resolution' => $assembly ? [
                    'outcome' => $assembly->outcome, 'reason' => $assembly->reason, 'meeting_date' => $assembly->meeting_date,
                    'meeting_type' => $assembly->meeting_type,
                    ...($institutional ? ['minutes_reference' => $assembly->minutes_reference, 'recorded_by_user_id' => $assembly->recorded_by_user_id] : []),
                ] : null] : null,
            'corrections' => $corrections,
            'membership' => $registration ? ['registered_at' => $registration->recorded_at, 'effective_date' => $registration->effective_date,
                ...($institutional ? ['register_reference' => $registration->register_reference, 'recorded_by_user_id' => $registration->recorded_by_user_id] : [])] : null,
            'formalization_enabled' => (bool) config('admissions.formalization_enabled'),
        ];
    }

    private function same(object $previous, array $expected): void
    {
        foreach ($expected as $key => $value) abort_unless((string) $previous->{$key} === (string) $value, 409, 'La actuación ya está registrada y no se sobrescribe.');
    }
}
