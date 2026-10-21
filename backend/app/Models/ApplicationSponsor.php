<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSponsor extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.application_sponsors';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'immutable_datetime', 'position' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function roleAssignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'role_assignment_id');
    }
}
