<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationEvent extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.application_events';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }
}
