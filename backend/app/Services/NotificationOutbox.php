<?php

namespace App\Services;

use App\Mail\ApplicationReceived;
use App\Mail\SponsorshipInvitation;
use App\Models\AdmissionApplication;
use App\Models\SponsorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationOutbox
{
    public static function enqueue(string $kind, string $entityId): void
    {
        DB::table('cchia_admissions.notification_outbox')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'kind' => $kind, 'deduplication_key' => $kind.':'.$entityId,
            'invitation_id' => $kind === 'sponsor_invitation' ? $entityId : null,
            'application_id' => $kind === 'application_received' ? $entityId : null,
            'created_at' => now(), 'available_at' => now(),
        ]);
    }

    public function process(int $limit = 25): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'abandoned' => 0];
        for ($i = 0; $i < min(100, max(1, $limit)); $i++) {
            $row = DB::transaction(function () {
                $row = DB::table('cchia_admissions.notification_outbox')->whereNull('sent_at')->whereNull('abandoned_at')
                    ->where('attempts', '<', 5)->where('available_at', '<=', now())
                    ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<=', now()->subMinutes(10)))
                    ->orderBy('created_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($row) DB::table('cchia_admissions.notification_outbox')->where('id', $row->id)
                    ->update(['locked_at' => now(), 'attempts' => $row->attempts + 1]);
                return $row;
            });
            if (!$row) break;
            try {
                $delivered = $this->deliver($row);
                DB::table('cchia_admissions.notification_outbox')->where('id', $row->id)->update([
                    $delivered ? 'sent_at' : 'abandoned_at' => now(), 'locked_at' => null, 'last_error_type' => null,
                ]);
                $result[$delivered ? 'sent' : 'abandoned']++;
            } catch (\Throwable $exception) {
                DB::table('cchia_admissions.notification_outbox')->where('id', $row->id)->update([
                    'locked_at' => null, 'last_error_type' => mb_substr(get_class($exception), 0, 255),
                    'available_at' => now()->addMinutes(min(60, 2 ** ($row->attempts + 1))),
                    'abandoned_at' => $row->attempts + 1 >= 5 ? now() : null,
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private function deliver(object $row): bool
    {
        if ($row->kind === 'application_received') {
            $application = AdmissionApplication::query()->findOrFail($row->application_id);
            Mail::to($application->email)->send(new ApplicationReceived($application));
            return true;
        }
        $invitation = SponsorInvitation::query()->with('intake')->findOrFail($row->invitation_id);
        $user = $invitation->target_user_id ? User::query()->find($invitation->target_user_id) : null;
        $service = app(PrivateSponsorships::class);
        if ($invitation->state !== 'pending' || $invitation->superseded_at !== null || !$user
            || mb_strtolower($user->email) !== $invitation->email || !$service->activeAssignment($user->id)
            || $invitation->intake->application()->exists()) return false;
        $data = $service->payload($invitation->intake);
        Mail::to($invitation->email)->send(new SponsorshipInvitation($data['name'] ?? $data['organizationName']));
        return true;
    }
}
