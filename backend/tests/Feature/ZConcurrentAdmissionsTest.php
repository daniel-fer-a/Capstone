<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('native-client')]
class ZConcurrentAdmissionsTest extends TestCase
{
    private function cleanIsolatedDatabase(): void
    {
        if (!app()->environment('testing') || config('database.default') !== 'pgsql' || !str_ends_with((string) config('database.connections.pgsql.database'), '_test') || config('database.connections.pgsql.url')) {
            throw new \RuntimeException('Las pruebas concurrentes solo admiten la base separada de testing.');
        }
        DB::statement('TRUNCATE TABLE cchia_admissions.intake_requests CASCADE');
    }

    protected function setUp(): void { parent::setUp(); $this->cleanIsolatedDatabase(); }
    protected function tearDown(): void { $this->cleanIsolatedDatabase(); parent::tearDown(); }

    private function body(): array
    {
        return ['membership'=>'member','legal'=>'natural','givenNames'=>'Persona','familyNames'=>'Concurrente Ficticia',
            'age'=>30,'identifier'=>'FICTICIO-QA','email'=>'concurrente@example.test','region'=>'Región ficticia','commune'=>'Comuna ficticia',
            'aiActivity'=>'Prueba ficticia de concurrencia.','sponsorEmails'=>['concurrente1@example.test','concurrente2@example.test'],
            'declaration_accepted'=>true,'sharing_accepted'=>true];
    }

    private function concurrently(array $bodies): array
    {
        $db = config('database.connections.pgsql');
        $env = ['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>config('app.key'),
            'DB_CONNECTION'=>'pgsql','DB_URL'=>'','DB_HOST'=>(string)$db['host'],'DB_PORT'=>(string)$db['port'],
            'DB_DATABASE'=>(string)$db['database'],'DB_USERNAME'=>(string)$db['username'],'DB_PASSWORD'=>(string)$db['password'],
            'DB_SSLMODE'=>(string)($db['sslmode'] ?? 'prefer'), 'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array',
            'ADMISSIONS_REQUIRE_MEMBER_SPONSORS'=>'true','ADMISSIONS_SPONSORSHIPS_ENABLED'=>'true'];
        $key=(string)Str::uuid(); $processes=[];
        foreach ($bodies as $i=>$body) {
            $process=new Process([PHP_BINARY,base_path('tests/Support/concurrent_request.php')],base_path(),$env);
            $process->setInput(json_encode(['body'=>$body,'key'=>$key,'ip'=>'127.0.10.'.($i+1)],JSON_THROW_ON_ERROR));
            $process->setTimeout(60); $process->start(); $processes[]=$process;
        }
        $results=[];
        foreach($processes as $process) {
            $process->wait();
            $this->assertSame(0,$process->getExitCode(),'El proceso de QA debe terminar sin errores.');
            $result=json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);
            $this->assertIsArray($result); $results[]=$result;
        }
        return $results;
    }

    public function test_four_simultaneous_identical_requests_recover_one_intake_and_access(): void
    {
        $results=$this->concurrently(array_fill(0,4,$this->body()));
        $this->assertSame([201,201,201,201],array_column($results,'status'));
        $this->assertCount(1,array_unique(array_column($results,'id')));
        $this->assertCount(1,array_unique(array_column($results,'token_hash')));
        $this->assertDatabaseCount('cchia_admissions.intake_requests',1);
        $this->assertDatabaseCount('cchia_admissions.sponsor_invitations',2);
    }

    public function test_simultaneous_different_payloads_with_one_key_cannot_create_two_intakes(): void
    {
        $results=$this->concurrently([$this->body(),[...$this->body(),'commune'=>'Otra comuna ficticia']]);
        $statuses=array_column($results,'status'); sort($statuses);
        $this->assertSame([201,409],$statuses);
        $this->assertDatabaseCount('cchia_admissions.intake_requests',1);
        $this->assertDatabaseCount('cchia_admissions.sponsor_invitations',2);
    }
}
