<?php

namespace App\Console\Commands;

use App\Services\WorkspaceChecks;
use Illuminate\Console\Command;

class WorkspaceOperations extends Command
{
    protected $signature='workspace:ops {action=summary} {--limit=250} {--full} {--json}';
    protected $description='Comprueba estructura, avisos e integridad de recursos de colaboración sin datos personales.';
    public function handle(WorkspaceChecks $checks): int
    {
        if(!in_array($this->argument('action'),['summary','files'],true)||!ctype_digit((string)$this->option('limit'))||(int)$this->option('limit')<1||(int)$this->option('limit')>100000){$this->line('{"ok":false,"error":"invalid_arguments"}');return 1;}
        try{$result=$this->argument('action')==='summary'?$checks->summary():$checks->files((int)$this->option('limit'),(bool)$this->option('full'));
            $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return $result['ok']?0:2;
        }catch(\Throwable $error){$this->line(json_encode(['ok'=>false,'error'=>'workspace_check_failed','exception_type'=>get_class($error)],JSON_THROW_ON_ERROR));return 1;}
    }
}
