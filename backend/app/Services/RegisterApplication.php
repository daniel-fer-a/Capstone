<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterApplication
{
    public function __construct(private readonly Model02Access $access) {}

    /** @param array<string, UploadedFile> $documents */
    public function register(array $data, array $documents): array
    {
        $written = [];
        $disk = Storage::disk(config('admissions.disk'));
        $token = Str::random(64);
        $submittedAt = now();

        try {
            $application = DB::transaction(function () use ($data, $documents, $disk, $token, $submittedAt, &$written) {
                $sponsors = [];
                if ($data['legal'] === 'juridica') {
                    foreach ($data['sponsors'] as $position => $userId) {
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
                $application = AdmissionApplication::query()->create([
                    'reference' => 'CCHIA-'.Str::ulid(),
                    'tracking_token_hash' => hash('sha256', $token),
                    'membership' => $data['membership'], 'legal' => $data['legal'],
                    'natural_name' => $natural ? $data['name'] : null,
                    'natural_identifier' => $natural ? $data['identifier'] : null,
                    'organization_name' => $natural ? null : $data['organizationName'],
                    'organization_identifier' => $natural ? null : $data['organizationId'],
                    'representative_name' => $natural ? null : $data['representativeName'],
                    'representative_identifier' => $natural ? null : $data['representativeId'],
                    'email' => $data['email'], 'address' => $data['address'], 'ai_activity' => $data['aiActivity'],
                    'status' => 'received', 'rules_version' => config('admissions.rules_version'),
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
                    'metadata' => ['status' => 'received', 'rules_version' => config('admissions.rules_version'),
                        'document_count' => count($documents)],
                    'occurred_at' => $submittedAt,
                ]);
                // Ejecutar también los constraints diferidos cuando hay una transacción exterior.
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

        return ['application' => $application, 'tracking_token' => $token];
    }
}
