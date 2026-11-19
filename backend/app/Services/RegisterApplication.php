<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RegisterApplication
{
    public function __construct(private readonly Model02Access $access) {}

    public function register(array $data, array $documents, ?string $submissionKey = null, ?array $intake = null): array
    {
        $written = [];
        $disk = Storage::disk(config('admissions.disk'));
        $token = Str::random(64);
        $submittedAt = now();
        $keyHash = $submissionKey === null ? null : hash('sha256', $submissionKey);
        $fingerprint = $this->fingerprint($data, $documents);
        $recoveredToken = null;
        $replayed = false;

        try {
            $application = DB::transaction(function () use ($data, $documents, $disk, $token, $submittedAt, $keyHash, $fingerprint, $intake, &$written, &$recoveredToken, &$replayed) {
                if ($keyHash !== null) {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr($keyHash, 0, 15))]);
                    $previous = DB::table('cchia_admissions.application_submissions')->where('key_hash', $keyHash)->first();
                    if ($previous !== null) {
                        if (!hash_equals($previous->request_hash, $fingerprint)) {
                            throw new ConflictHttpException('Este envío ya se utilizó con otros datos. No vuelvas a enviarlo como una solicitud nueva sin comprobar su recepción.');
                        }
                        if ($submittedAt->greaterThanOrEqualTo($previous->recoverable_until)) {
                            throw new ConflictHttpException('Terminó el plazo de recuperación de este envío. Usa el comprobante o contacta a la CCHIA antes de volver a postular.');
                        }
                        $recoveredToken = Crypt::decryptString($previous->encrypted_tracking_token);
                        $replayed = true;

                        return AdmissionApplication::query()->findOrFail($previous->application_id);
                    }
                }
                $sponsors = $intake['sponsors'] ?? [];
                if (!empty($data['sponsors'])) {
                    foreach ($data['sponsors'] as $position => $userId) {
                        $userId = strtolower($userId);
                        $assignment = $this->access->effectiveGlobalAssignments($userId, $submittedAt)
                            ->where('ra.role_code', 'socio')->orderBy('ra.id')->sharedLock()
                            ->first(['ra.id as assignment_id']);
                        if ($assignment === null) {
                            throw ValidationException::withMessages([
                                'sponsors.'.$position => 'El patrocinador no tiene una asignación de socio global activa y vigente.',
                            ]);
                        }
                        $sponsors[] = ['sponsor_user_id' => $userId, 'role_assignment_id' => $assignment->assignment_id,
                            'position' => $position + 1, 'checked_at' => $submittedAt];
                    }
                }

                $natural = $data['legal'] === 'natural';
                $rulesVersion = $intake !== null ? config('admissions.staged_rules_version')
                    : ($natural && $data['membership'] === 'member' && !config('admissions.require_member_sponsors')
                    ? config('admissions.pilot_rules_version') : config('admissions.rules_version'));
                $application = AdmissionApplication::query()->create([
                    'intake_id' => $intake['intake_id'] ?? null,
                    'reference' => 'CCHIA-'.Str::ulid(),
                    'tracking_token_hash' => hash('sha256', $token),
                    'membership' => $data['membership'], 'legal' => $data['legal'],
                    'natural_name' => $natural ? $data['name'] : null,
                    'natural_identifier' => $natural ? $data['identifier'] : null,
                    'natural_given_names' => $natural ? ($data['givenNames'] ?? null) : null,
                    'natural_family_names' => $natural ? ($data['familyNames'] ?? null) : null,
                    'natural_age' => $natural ? ($data['age'] ?? null) : null,
                    'region' => $data['region'] ?? null, 'commune' => $data['commune'] ?? null,
                    'organization_name' => $natural ? null : $data['organizationName'],
                    'organization_identifier' => $natural ? null : $data['organizationId'],
                    'representative_name' => $natural ? null : $data['representativeName'],
                    'representative_identifier' => $natural ? null : $data['representativeId'],
                    'email' => $data['email'], 'address' => $data['address'], 'ai_activity' => $data['aiActivity'],
                    'status' => 'received', 'rules_version' => $rulesVersion,
                    'declaration_accepted_at' => $submittedAt, 'submitted_at' => $submittedAt,
                ]);

                foreach ($sponsors as $sponsor) {
                    DB::table('cchia_admissions.application_sponsors')->insert(['application_id' => $application->id, ...$sponsor]);
                }
                foreach ($documents as $kind => $file) {
                    $mime = $file->getMimeType();
                    $extension = match ($mime) {
                        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                        default => throw new \RuntimeException('Tipo de archivo no admitido.'),
                    };
                    $name = (string) Str::uuid().'.'.$extension;
                    $key = $application->id.'/'.$name;
                    $written[] = $key;
                    if ($disk->putFileAs($application->id, $file, $name, ['visibility' => 'private']) === false) {
                        throw new \RuntimeException('No fue posible guardar un antecedente.');
                    }
                    $original = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename($file->getClientOriginalName())) ?: 'antecedente.'.$extension;
                    $application->documents()->create([
                        'kind' => $kind, 'disk' => config('admissions.disk'), 'storage_key' => $key,
                        'original_name' => mb_substr($original, 0, 255), 'mime_type' => $mime,
                        'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                        'uploaded_at' => $submittedAt,
                    ]);
                }
                $application->events()->create([
                    'actor_kind' => 'applicant', 'action' => 'application.submitted', 'outcome' => 'success',
                    'metadata' => ['status' => 'received', 'rules_version' => $rulesVersion,
                        'document_count' => count($documents)],
                    'occurred_at' => $submittedAt,
                ]);
                if ($keyHash !== null) {
                    DB::table('cchia_admissions.application_submissions')->insert([
                        'key_hash' => $keyHash, 'request_hash' => $fingerprint,
                        'application_id' => $application->id,
                        'encrypted_tracking_token' => Crypt::encryptString($token),
                        'created_at' => $submittedAt,
                        'recoverable_until' => $submittedAt->copy()->addDay(),
                    ]);
                }
                if ($intake !== null) {
                    NotificationOutbox::enqueue('application_received', $application->id);
                    DB::table('cchia_admissions.intake_events')->insert([
                        'id' => (string) Str::uuid(), 'intake_id' => $intake['intake_id'],
                        'actor_kind' => 'applicant', 'action' => 'intake.submitted',
                        'metadata' => json_encode(['application_id' => $application->id], JSON_THROW_ON_ERROR), 'occurred_at' => $submittedAt,
                    ]);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');

                return $application;
            });
        } catch (Throwable $exception) {
            foreach ($written as $key) {
                try {
                    $disk->delete($key);
                } catch (Throwable) {
                    Log::warning('admissions.orphan_cleanup_required', ['storage_key' => $key]);
                }
            }
            throw $exception;
        }

        return ['application' => $application, 'tracking_token' => $recoveredToken ?? $token, 'replayed' => $replayed];
    }

    private function fingerprint(array $data, array $documents): string
    {
        ksort($data);
        $data['declaration_accepted'] = true;
        if (isset($data['age'])) {
            $data['age'] = (int) $data['age'];
        }
        $files = [];
        foreach ($documents as $kind => $file) {
            $files[$kind] = [
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'name' => $file->getClientOriginalName(),
            ];
        }
        ksort($files);

        return hash('sha256', json_encode(['data' => $data, 'files' => $files], JSON_THROW_ON_ERROR));
    }
}
