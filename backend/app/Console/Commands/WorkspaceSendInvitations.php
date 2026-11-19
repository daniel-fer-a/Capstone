<?php

namespace App\Console\Commands;

use App\Services\WorkspaceMailOutbox;
use Illuminate\Console\Command;

class WorkspaceSendInvitations extends Command
{
    protected $signature='workspace:send-invitations {--limit=25}';
    protected $description='Procesa invitaciones privadas de comisiones sin imprimir destinatarios ni códigos.';
    public function handle(): int
    {
        if(!config('workspace.enabled')){$this->line('{"enabled":false}');return 0;}
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT);
        if($limit===false||$limit<1||$limit>100){$this->error('El límite debe estar entre 1 y 100.');return 1;}
        $this->line(json_encode(app(WorkspaceMailOutbox::class)->deliver($limit),JSON_THROW_ON_ERROR));return 0;
    }
}
