<?php

namespace App\Services;

use App\Models\AdmissionIntake;
use App\Models\SponsorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PrivateSponsorships
{
    public function __construct(private readonly Model02Access $access) {}

    public function create(array $data, string $key): array
    {
        abort_unless(config('admissions.sponsorships_enabled'), 503, 'La solicitud de patrocinios no está disponible en este momento.');
        $emails = $data['sponsorEmails'];
        unset($data['sponsorEmails']);
        ksort($data);
        $data['age'] = isset($data['age']) ? (int) $data['age'] : null;
        $data['declaration_accepted'] = true;
        $data['sharing_accepted'] = true;
        $fingerprint = hash('sha256', json_encode([$data, $emails], JSON_THROW_ON_ERROR));
        $hash = hash('sha256', $key);

        return DB::transaction(function () use ($data, $emails, $fingerprint, $hash): array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr($hash, 0, 15))]);
            $existing = AdmissionIntake::query()->where('submission_key_hash', $hash)->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash, $fingerprint) || now()->greaterThanOrEqualTo($existing->recovery_until) || $existing->encrypted_access_token === '') {
                    throw new ConflictHttpException('Este envío ya se utilizó. Continúa con tu folio privado y código de acceso.');
                }
                return ['intake' => $existing, 'access_token' => Crypt::decryptString($existing->encrypted_access_token), 'replayed' => true];
            }
            $token = Str::random(64);
            $intake = AdmissionIntake::query()->create([
                'reference' => 'CCHIA-PRE-'.Str::ulid(), 'access_token_hash' => hash('sha256', $token),
                'encrypted_access_token' => Crypt::encryptString($token), 'recovery_until' => now()->addDay(),
                'submission_key_hash' => $hash, 'request_hash' => $fingerprint,
                'legal' => $data['legal'], 'encrypted_payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);
            $this->invite($intake, $emails);
            $this->event($intake, 'intake.created', 'applicant', null, ['sharing_accepted' => true, 'invitation_count' => 2]);
            return ['intake' => $intake, 'access_token' => $token, 'replayed' => false];
        });
    }

    public function authorizeOwner(string $reference, string $token, bool $lock = false): AdmissionIntake
    {
        $query = AdmissionIntake::query()->where('reference', $reference);
        if ($lock) $query->lockForUpdate();
        $intake = $query->first();
        abort_unless($intake && hash_equals($intake->access_token_hash, hash('sha256', $token)), 404, 'No encontramos una solicitud con esos datos.');
        return $intake;
    }

    public function payload(AdmissionIntake $intake): array
    {
        return json_decode(Crypt::decryptString($intake->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
    }

    public function activeAssignment(string $userId): ?object
    {
        return $this->access->effectiveGlobalAssignments($userId, now())->where('ra.role_code', 'socio')
            ->orderBy('ra.id')->sharedLock()->first(['ra.id as assignment_id']);
    }

    public function currentInvitations(AdmissionIntake $intake)
    {
        return $intake->invitations()->whereNull('superseded_at')->get();
    }

    public function ready(AdmissionIntake $intake): bool
    {
        $invitations = $this->currentInvitations($intake);
        return $invitations->count() === 2 && $invitations->every(fn ($invitation) => $invitation->state === 'accepted'
            && $invitation->decided_by_user_id === $invitation->target_user_id
            && $this->activeAssignment($invitation->target_user_id) !== null);
    }

    public function ownerData(AdmissionIntake $intake): array
    {
        $application = $intake->application()->first();
        $ready = $application !== null || $this->ready($intake);
        return [
            'id' => $intake->id, 'reference' => $intake->reference, 'legal' => $intake->legal,
            'created_at' => $intake->created_at->toIso8601String(),
            'stage' => $application ? 'submitted' : ($ready ? 'ready_for_documents' : 'waiting_sponsors'),
            'ready_for_documents' => $ready, 'payload' => $this->payload($intake),
            'invitations' => $this->currentInvitations($intake)->map(function ($invitation) use ($application): array {
                $valid = $invitation->state !== 'accepted' || $application !== null || $this->activeAssignment($invitation->target_user_id) !== null;
                return ['position' => $invitation->position, 'email' => $invitation->email,
                    'state' => $valid ? $invitation->state : 'confirmation_unavailable',
                    'decided_at' => $invitation->decided_at?->toIso8601String()];
            })->values()->all(),
            'application' => $application ? ['reference' => $application->reference, 'status' => $application->status,
                'submitted_at' => $application->submitted_at->toIso8601String()] : null,
        ];
    }

    public function replace(AdmissionIntake $intake, array $emails, string $key): void
    {
        $operation = hash('sha256', $key);
        $fingerprint = hash('sha256', json_encode($emails, JSON_THROW_ON_ERROR));
        $previous = DB::table('cchia_admissions.intake_events')->where('intake_id', $intake->id)
            ->where('action', 'intake.sponsors_replaced')->where('metadata->operation_hash', $operation)->first();
        if ($previous) {
            abort_unless(hash_equals(json_decode($previous->metadata, true)['emails_hash'], $fingerprint), 409);
            return;
        }
        abort_if($intake->application()->exists(), 409, 'La postulación ya fue enviada y sus patrocinios están registrados.');
        $intake->invitations()->whereNull('superseded_at')->update(['superseded_at' => now()]);
        $this->invite($intake, $emails);
        $this->event($intake, 'intake.sponsors_replaced', 'applicant', null, [
            'confirmations_reset' => true, 'operation_hash' => $operation, 'emails_hash' => $fingerprint,
        ]);
    }

    public function decide(SponsorInvitation $invitation, User $user, string $decision): void
    {
        abort_unless($invitation->superseded_at === null && $invitation->target_user_id === $user->id
            && $invitation->email === mb_strtolower($user->email), 404);
        $assignment = $this->activeAssignment($user->id);
        abort_unless($assignment, 403, 'Se requiere una asignación de socio activa y vigente.');
        abort_if($invitation->intake->application()->exists(), 409, 'La solicitud ya fue enviada.');
        if ($invitation->state === $decision) return;
        abort_unless($invitation->state === 'pending', 409, 'Esta invitación ya tiene una respuesta registrada.');
        $invitation->update(['state' => $decision, 'decided_by_user_id' => $user->id,
            'role_assignment_id' => $assignment->assignment_id, 'decided_at' => now()]);
        $this->event($invitation->intake, 'sponsorship.'.$decision, 'member', $user->id, ['invitation_id' => $invitation->id]);
    }

    public function confirmedSponsors(AdmissionIntake $intake): array
    {
        if (!$this->ready($intake)) {
            throw ValidationException::withMessages(['sponsors' => 'Se requieren dos confirmaciones vigentes antes de adjuntar los documentos.']);
        }
        return $this->currentInvitations($intake)->map(fn ($invitation) => [
            'sponsor_user_id' => $invitation->target_user_id,
            'role_assignment_id' => $this->activeAssignment($invitation->target_user_id)->assignment_id,
            'position' => $invitation->position, 'checked_at' => now(),
            'invitation_id' => $invitation->id, 'confirmed_at' => $invitation->decided_at,
        ])->all();
    }

    public function event(AdmissionIntake $intake, string $action, string $actor, ?string $userId = null, array $metadata = []): void
    {
        DB::table('cchia_admissions.intake_events')->insert([
            'id' => (string) Str::uuid(), 'intake_id' => $intake->id, 'actor_kind' => $actor,
            'actor_user_id' => $userId, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    private function invite(AdmissionIntake $intake, array $emails): void
    {
        foreach ($emails as $index => $email) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            $eligible = $user && $this->activeAssignment($user->id) !== null;
            $invitation = SponsorInvitation::query()->create([
                'intake_id' => $intake->id, 'position' => $index + 1, 'email' => $email,
                'target_user_id' => $eligible ? $user->id : null, 'created_at' => now(),
            ]);
            if ($eligible) NotificationOutbox::enqueue('sponsor_invitation', $invitation->id);
        }
    }
}
