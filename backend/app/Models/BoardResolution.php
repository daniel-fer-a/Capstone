<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BoardResolution extends Model
{
    use HasUuids;

    protected $table = 'cchia_admissions.board_resolutions';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'meeting_date' => 'immutable_date'];
    }
}
