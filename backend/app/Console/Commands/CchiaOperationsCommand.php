<?php

namespace App\Console\Commands;

use App\Services\CchiaOperations;
use Illuminate\Console\Command;

class CchiaOperationsCommand extends Command
{
    protected $signature = 'cchia:ops {action=summary} {--json} {--limit=250} {--full} {--max-age=180}';
    protected $description = 'Comprueba operación, integridad privada, permisos agregados y latido del scheduler sin datos personales.';

    public function handle(CchiaOperations $service): int
    {
        if (!in_array($this->argument('action'), ['summary','files','permissions','mail','heartbeat','heartbeat-status'], true)
            || !ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1 || (int) $this->option('limit') > 100000
            || !ctype_digit((string) $this->option('max-age')) || (int) $this->option('max-age') < 30 || (int) $this->option('max-age') > 3600) {
            $this->line(json_encode(['ok' => false, 'error' => 'invalid_arguments'], JSON_THROW_ON_ERROR));
            return 1;
        }
        try {
            $result = match ($this->argument('action')) {
                'summary' => $service->summary(), 'files' => $service->files((int) $this->option('limit'), (bool) $this->option('full')),
                'permissions' => $service->permissions(), 'mail' => $service->mail(), 'heartbeat' => $service->pulse(),
                'heartbeat-status' => $service->heartbeat((int) $this->option('max-age')),
            };
            $this->line(json_encode(['action' => $this->argument('action'), 'generated_at' => now()->toIso8601String(), ...$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return ($result['ok'] ?? (($result['exhausted'] ?? 0) === 0)) ? 0 : 2;
        } catch (\Throwable $exception) {
            $this->line(json_encode(['ok' => false, 'error' => 'operation_check_failed', 'exception_type' => get_class($exception)], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
