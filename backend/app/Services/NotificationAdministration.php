<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationAdministration
{
    public function retry(string $id, string $reason, string $operationKey, User $actor): array
    {
        return DB::transaction(function () use ($id, $reason, $operationKey, $actor) {
            $hash = hash('sha256', $actor->id.':'.$operationKey);
            DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr($hash, 0, 15))]);
            $previous = DB::table('cchia_admissions.notification_retries')->where('operation_hash', $hash)->first();
            if ($previous) {
                abort_unless($previous->notification_id === $id && $previous->reason === $reason && $previous->requested_by_user_id === $actor->id, 409);
                return ['id' => $id, 'retry_id' => $previous->id, 'replayed' => true];
            }
            $row = DB::table('cchia_admissions.notification_outbox')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->sent_at === null && $row->abandoned_at !== null && (int) $row->attempts === 5 && $row->last_error_type !== null
                && ($row->locked_at === null || now()->subMinutes(10)->greaterThanOrEqualTo($row->locked_at)), 409, 'Solo se reactivan avisos agotados por error que no estén procesados ni en ejecución.');
            if ($row->kind === 'sponsor_invitation') {
                $invitation = \App\Models\SponsorInvitation::query()->find($row->invitation_id);
                $user = $invitation?->target_user_id ? User::query()->find($invitation->target_user_id) : null;
                abort_unless($invitation && $user && $invitation->state === 'pending' && $invitation->superseded_at === null
                    && mb_strtolower($user->email) === $invitation->email && app(PrivateSponsorships::class)->activeAssignment($user->id)
                    && !$invitation->intake->application()->exists(), 409, 'La invitación ya no está vigente.');
            }
            $retryId = (string) Str::uuid();
            DB::table('cchia_admissions.notification_retries')->insert(['id' => $retryId, 'notification_id' => $id,
                'operation_hash' => $hash, 'reason' => $reason, 'previous_attempts' => $row->attempts,
                'previous_error_type' => $row->last_error_type, 'requested_by_user_id' => $actor->id, 'requested_at' => now()]);
            DB::table('cchia_admissions.notification_outbox')->where('id', $id)->update([
                'attempts' => 0, 'available_at' => now(), 'abandoned_at' => null, 'locked_at' => null, 'last_error_type' => null,
            ]);
            app(CaseOperationsAccess::class)->audit($actor, 'admissions.notification.requeued', 'notification_outbox', $id, ['retry_id' => $retryId]);
            return ['id' => $id, 'retry_id' => $retryId, 'replayed' => false];
        });
    }
}
