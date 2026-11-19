<?php

namespace Tests\Support;

use App\Models\SponsorInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait AdmissionWorkflowFixtures
{
    private function member(string $email, array $changes = []): User
    {
        $user = User::query()->create(['name' => 'Socio ficticio', 'email' => $email, 'password' => 'Solo-pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => 'socio',
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(), ...$changes,
        ]);
        return $user;
    }

    private function data(string $legal = 'natural'): array
    {
        $data = ['membership' => 'member', 'legal' => $legal, 'email' => 'postulante@example.test',
            'region' => 'La Araucanía', 'commune' => 'Temuco', 'aiActivity' => 'Investigación y desarrollo en IA.',
            'sponsorEmails' => ['socio1@example.test', 'socio2@example.test'], 'declaration_accepted' => true, 'sharing_accepted' => true];
        return $legal === 'natural' ? [...$data, 'givenNames' => 'Persona', 'familyNames' => 'Ficticia', 'age' => 30, 'identifier' => 'IDENTIDAD-PRIVADA']
            : [...$data, 'organizationName' => 'Organización ficticia', 'organizationId' => 'RUT-ORGANIZACION',
                'representativeName' => 'Representante Ficticia', 'representativeId' => 'RUT-REPRESENTANTE'];
    }

    private function create(string $legal = 'natural', ?array $data = null, ?string $key = null)
    {
        return $this->postJson('/api/v1/intakes', $data ?? $this->data($legal), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function credentials(array $data): array
    {
        return ['reference' => $data['reference'], 'access_token' => $data['access_token']];
    }

    private function acceptBoth(array $data): array
    {
        $members = [];
        foreach (['socio1@example.test', 'socio2@example.test'] as $email) {
            $member = User::query()->where('email', $email)->firstOrFail();
            $members[] = $member;
            $invitation = SponsorInvitation::query()->where('intake_id', $data['id'])->where('email', $email)->whereNull('superseded_at')->firstOrFail();
            Sanctum::actingAs($member);
            $this->postJson('/api/v1/sponsor/invitations/'.$invitation->id.'/decision', ['decision' => 'accepted', 'confirmation' => true])->assertOk();
        }
        return $members;
    }

    private function ready(string $legal = 'natural'): array
    {
        $this->member('socio1@example.test');
        $this->member('socio2@example.test');
        $data = $this->create($legal)->assertCreated()->json('data');
        $this->acceptBoth($data);
        return $data;
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name.'.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    private function reviewer(bool $canDecide = true): User
    {
        config(['admissions.review_enabled' => true]);
        $user = User::query()->create(['name' => 'Directorio ficticio', 'email' => 'directorio@example.test', 'password' => 'Pruebas-locales-123']);
        DB::table('cchia_m02.role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_code' => 'directorio',
            'scope_id' => DB::table('cchia_m02.access_scopes')->where('kind', 'global')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'accepted_at' => now()->subDay(),
        ]);
        foreach (['admissions.read', 'admissions.documents.read', 'admissions.review.start', ...($canDecide ? ['admissions.decisions.record', 'admissions.corrections.manage', 'admissions.appeals.record', 'admissions.membership.formalize'] : [])] as $permission) {
            DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code' => 'directorio', 'permission_code' => $permission]);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    private function agreement(string $outcome = 'approved'): array
    {
        return ['outcome' => $outcome, 'reason' => 'Acuerdo ficticio del Directorio para la prueba de integración.',
            'minutes_reference' => 'ACTA-FICTICIA-INTERNA', 'meeting_date' => now('America/Santiago')->toDateString()];
    }


    private function submitted(string $legal = 'natural'): array
    {
        $intake = $this->ready($legal);
        $body = [...$this->credentials($intake), 'declaration_accepted' => true];
        if ($legal === 'juridica') $body['documents'] = ['estatutos' => $this->pdf('estatutos'), 'vigencia' => $this->pdf('vigencia')];
        $receipt = $this->post('/api/v1/intakes/submit', $body, ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->reviewer();
        $this->postJson($this->url($receipt).'/start-review')->assertOk();
        return $receipt;
    }

    private function url(array $receipt): string { return '/api/v1/admin/applications/'.$receipt['id']; }
    private function privateAccess(array $receipt): array { return ['reference' => $receipt['reference'], 'tracking_token' => $receipt['tracking_token']]; }
    private function rejected(): array
    {
        $receipt = $this->submitted();
        $this->postJson($this->url($receipt).'/decision', $this->agreement('rejected'))->assertOk();
        return $receipt;
    }
    private function appealBody(array $receipt): array { return [...$this->privateAccess($receipt), 'reason' => 'Solicito el pronunciamiento de la próxima Asamblea.', 'confirmation' => true]; }
    private function assemblyBody(string $outcome = 'approved'): array { return [...$this->agreement($outcome), 'meeting_type' => 'ordinary', 'confirmation' => true]; }
    private function requestDocuments(array $receipt, array $kinds = ['estatutos']): string
    {
        return $this->postJson($this->url($receipt).'/corrections', ['reason' => 'Adjuntar una copia legible.', 'requested_kinds' => $kinds], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.id');
    }
    private function upload(array $receipt, string $id, string $name = 'nuevo')
    {
        return $this->post('/api/v1/applications/corrections/submit', [...$this->privateAccess($receipt), 'correction_id' => $id, 'confirmation' => true, 'documents' => ['estatutos' => $this->pdf($name)]], ['Accept' => 'application/json']);
    }


    private function registered(string $legal = 'natural'): array
    {
        $receipt=$this->submitted($legal);
        $this->postJson($this->url($receipt).'/decision',$this->agreement())->assertOk();
        $this->postJson($this->url($receipt).'/formalize',['register_reference'=>'QA-REGISTRO-001','effective_date'=>now('America/Santiago')->toDateString(),'confirmation'=>true])->assertOk();
        $receipt['registration_id']=DB::table('cchia_admissions.membership_registrations')->where('application_id',$receipt['id'])->value('id');
        return $receipt;
    }

    private function operationsPermissions(): void
    {
        foreach(['admissions.membership.read','admissions.membership.export','admissions.notifications.manage'] as $code) DB::table('cchia_m02.role_permissions')->insertOrIgnore(['role_code'=>'directorio','permission_code'=>$code]);
    }

    private function exhausted(array $receipt): string
    {
        $row=DB::table('cchia_admissions.notification_outbox')->where('application_id',$receipt['id'])->where('kind','application_received')->first();
        DB::table('cchia_admissions.notification_outbox')->where('id',$row->id)->update(['attempts'=>5,'abandoned_at'=>now(),'last_error_type'=>'RuntimeException']);
        return $row->id;
    }
}
