<?php

namespace App\Http\Controllers;

use App\Services\CaseOperationsAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipRegisterController extends Controller
{
    public function __construct(private readonly CaseOperationsAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'admissions.membership.read', 'membership_register', 'list');
        $data = $this->filters($request);
        $query = $this->query($data)->select(['m.id', 'm.register_reference', 'm.legal', 'm.effective_date',
            'm.recorded_at', 'a.natural_name', 'a.organization_name']);
        return response()->json($query->orderByDesc('m.effective_date')->orderBy('m.id')->paginate(20))->header('Cache-Control', 'no-store');
    }

    public function show(string $registration, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'admissions.membership.read', 'membership_registration', $registration);
        $row = $this->query([])->where('m.id', $registration)->first([
            'm.id', 'm.register_reference', 'm.legal', 'm.effective_date', 'm.recorded_at',
            'a.reference as application_reference', 'a.natural_name', 'a.natural_identifier', 'a.organization_name',
            'a.organization_identifier', 'a.representative_name', 'a.representative_identifier',
            'a.email', 'a.address', 'a.region', 'a.commune', 'a.ai_activity',
        ]);
        abort_unless($row, 404);
        return response()->json(['data' => $row])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->access->authorize($request->user(), 'admissions.membership.read', 'membership_register', 'export');
        $this->access->authorize($request->user(), 'admissions.membership.export', 'membership_register', 'export');
        $query = $this->query($this->filters($request));
        $count = (clone $query)->count();
        $this->access->audit($request->user(), 'admissions.membership.export_started', 'membership_register', 'export', ['records' => $count]);
        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Registro', 'Modalidad', 'Fecha de ingreso', 'Nombre o razón social', 'Identificación',
                'Representante', 'RUT del representante', 'Correo', 'Domicilio declarado', 'Folio de postulación'], ',', '"', '');
            foreach ($query->orderBy('m.id')->cursor(['m.register_reference', 'm.legal', 'm.effective_date',
                'a.natural_name', 'a.natural_identifier', 'a.organization_name', 'a.organization_identifier',
                'a.representative_name', 'a.representative_identifier', 'a.email', 'a.address', 'a.reference']) as $row) {
                $cells = [$row->register_reference, $row->legal, $row->effective_date, $row->natural_name ?? $row->organization_name,
                    $row->natural_identifier ?? $row->organization_identifier, $row->representative_name,
                    $row->representative_identifier, $row->email, $row->address, $row->reference];
                fputcsv($stream, array_map(fn ($v) => self::csvCell((string) $v), $cells), ',', '"', '');
            }
            fclose($stream);
        }, 'cchia_registro_ingresos_'.now('America/Santiago')->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public static function csvCell(string $value): string
    {
        $value = str_replace("\0", '', $value);
        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'".$value : $value;
    }

    private function query(array $data): Builder
    {
        $query = DB::table('cchia_admissions.membership_registrations as m')->join('cchia_admissions.applications as a', 'a.id', '=', 'm.application_id');
        if (!empty($data['legal'])) $query->where('m.legal', $data['legal']);
        if (isset($data['q']) && trim($data['q']) !== '') {
            $pattern = '%'.addcslashes(trim($data['q']), '%_\\').'%';
            $query->where(fn ($q) => $q->where('m.register_reference', 'ilike', $pattern)
                ->orWhere('a.natural_name', 'ilike', $pattern)->orWhere('a.organization_name', 'ilike', $pattern));
        }
        return $query;
    }

    private function filters(Request $request): array
    {
        return $request->validate(['legal' => ['sometimes', 'in:natural,juridica'],
            'q' => ['sometimes', 'string', 'max:100', 'not_regex:/\\x00/'], 'page' => ['sometimes', 'integer', 'min:1']]);
    }
}
