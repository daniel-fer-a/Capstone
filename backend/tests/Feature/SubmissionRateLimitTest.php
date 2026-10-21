<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SubmissionRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_submission_rate_is_limited(): void
    {
        $data = ['membership' => 'member', 'legal' => 'natural', 'name' => 'Persona de prueba',
            'identifier' => 'Prueba', 'email' => 'test@example.org', 'address' => 'Domicilio',
            'aiActivity' => 'Actividad IA', 'declaration_accepted' => true];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/applications', $data)->assertCreated();
        }
        $this->postJson('/api/v1/applications', $data)->assertStatus(429);
    }
}
