<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one container start cost, in time and in the unit clouds bill in.
 *
 * Written by whoever asked for the container, not by the container: the broker
 * reports when it began and ended, and the caller is the only side that knows
 * which file and which person the work was for.
 */
class RenderRun extends Model
{
    protected $fillable = [
        'kind', 'scratch', 'product_file_id', 'user_id', 'triangles', 'source_bytes',
        'asked_at', 'started_at', 'finished_at', 'queued_seconds', 'run_seconds',
        'vcpu_seconds', 'cpu_quota', 'memory', 'status', 'exit_code', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'queued_seconds' => 'float',
            'run_seconds' => 'float',
            'vcpu_seconds' => 'float',
        ];
    }

    public function productFile()
    {
        return $this->belongsTo(ProductFile::class);
    }
}
