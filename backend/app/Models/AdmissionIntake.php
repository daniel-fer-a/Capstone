<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdmissionIntake extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.intake_requests';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['access_token_hash', 'encrypted_access_token', 'encrypted_payload', 'submission_key_hash', 'request_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'recovery_until' => 'immutable_datetime'];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SponsorInvitation::class, 'intake_id')->orderBy('position');
    }

    public function application(): HasOne
    {
        return $this->hasOne(AdmissionApplication::class, 'intake_id');
    }
}
