<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApiBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_anonymous_browser_submission_is_stateless_but_login_keeps_csrf_protection(): void
    {
        config(['admissions.require_member_sponsors' => false]);
        $this->app['env'] = 'local';
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/postular'])
            ->postJson('/api/v1/applications', ['membership' => 'member', 'legal' => 'natural', 'givenNames' => 'Persona',
                'familyNames' => 'de prueba', 'age' => 30, 'identifier' => 'Prueba', 'email' => 'persona@example.test',
                'region' => 'Región de prueba', 'commune' => 'Comuna de prueba', 'aiActivity' => 'Investigación en IA.', 'declaration_accepted' => true])->assertCreated();
        $this->postJson('/api/v1/session/login', ['email' => 'persona@example.test', 'password' => 'Prueba-local-123'])->assertStatus(419);
    }

    public function test_api_guests_receive_json_401_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/admin/applications')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
}
