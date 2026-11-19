<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorInvitation extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.sponsor_invitations';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(AdmissionIntake::class, 'intake_id');
    }
}
