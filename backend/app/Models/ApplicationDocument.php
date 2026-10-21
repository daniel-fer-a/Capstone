<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.application_documents';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['storage_key', 'disk'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'uploaded_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }
}
