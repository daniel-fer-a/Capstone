<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SubmissionRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_submission_rate_is_limited(): void
    {
        config(['admissions.require_member_sponsors' => false]);
        $data = ['membership' => 'member', 'legal' => 'natural', 'name' => 'Persona de prueba',
            'givenNames' => 'Persona', 'familyNames' => 'de prueba', 'age' => 30,
            'region' => 'Región de prueba', 'commune' => 'Comuna de prueba', 'identifier' => 'Prueba', 'email' => 'test@example.org', 'address' => 'Domicilio',
            'aiActivity' => 'Actividad IA', 'declaration_accepted' => true];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/applications', $data)->assertCreated();
        }
        $this->postJson('/api/v1/applications', $data)->assertStatus(429);
    }
}
