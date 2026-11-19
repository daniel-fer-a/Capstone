<?php

namespace App\Http\Controllers;

use App\Services\CaseOperationsAccess;
use App\Services\NotificationAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationAdministrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        app(CaseOperationsAccess::class)->authorize($request->user(), 'admissions.notifications.manage', 'notification_outbox', 'list');
        $data = $request->validate(['status' => ['sometimes', 'in:pending,sent,failed,omitted'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = DB::table('cchia_admissions.notification_outbox')->select(['id', 'kind', 'attempts', 'created_at', 'available_at', 'locked_at', 'sent_at', 'abandoned_at', 'last_error_type']);
        match ($data['status'] ?? '') {
            'pending' => $query->whereNull('sent_at')->whereNull('abandoned_at'),
            'sent' => $query->whereNotNull('sent_at'),
            'failed' => $query->whereNull('sent_at')->whereNotNull('abandoned_at')->whereNotNull('last_error_type'),
            'omitted' => $query->whereNull('sent_at')->whereNotNull('abandoned_at')->whereNull('last_error_type'),
            default => null,
        };
        $result = $query->orderByDesc('created_at')->orderBy('id')->paginate(20);
        $result->getCollection()->transform(fn ($row) => [
            'id' => $row->id, 'kind' => $row->kind, 'attempts' => (int) $row->attempts, 'created_at' => $row->created_at,
            'available_at' => $row->available_at, 'sent_at' => $row->sent_at, 'abandoned_at' => $row->abandoned_at,
            'failed' => $row->last_error_type !== null,
            'can_retry' => $row->sent_at === null && $row->abandoned_at !== null && $row->last_error_type !== null && $row->attempts === 5
                && ($row->locked_at === null || now()->subMinutes(10)->greaterThanOrEqualTo($row->locked_at)),
        ]);
        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function retry(string $notification, Request $request): JsonResponse
    {
        app(CaseOperationsAccess::class)->authorize($request->user(), 'admissions.notifications.manage', 'notification_outbox', $notification);
        if (is_string($request->input('reason'))) $request->merge(['reason' => trim($request->input('reason'))]);
        $request->merge(['operation_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/\\x00/'],
            'confirmation' => ['required', 'accepted'], 'operation_key' => ['required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/D']]);
        return response()->json(['data' => app(NotificationAdministration::class)->retry($notification, $data['reason'], $data['operation_key'], $request->user())])->header('Cache-Control', 'no-store');
    }
}
