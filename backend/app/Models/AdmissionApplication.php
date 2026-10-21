<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionApplication extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.applications';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['tracking_token_hash'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime', 'declaration_accepted_at' => 'immutable_datetime',
            'decision_at' => 'immutable_datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(ApplicationSponsor::class, 'application_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class, 'application_id')->orderBy('occurred_at');
    }
}
