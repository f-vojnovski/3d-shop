<?php

namespace App\Console\Commands;

use App\Jobs\ScaleThumbnail;
use App\Models\ProductFile;
use Illuminate\Console\Command;

class ScaleThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:scale {--sync : Shrink them now instead of queueing}';

    protected $description = 'Shrink every card picture that has not been through the job yet.';

    public function handle(): int
    {
        $pending = ProductFile::query()
            ->where('kind', ProductFile::KIND_THUMBNAIL)
            ->whereNull('superseded_at')
            ->get()
            ->reject(fn (ProductFile $file) => (bool) ($file->meta['scaled'] ?? false));

        foreach ($pending as $file) {
            $this->option('sync')
                ? (new ScaleThumbnail($file->id))->handle()
                : ScaleThumbnail::dispatch($file->id);
        }

        $this->info(sprintf(
            '%s %d %s.',
            $this->option('sync') ? 'Shrank' : 'Queued',
            $pending->count(),
            $pending->count() === 1 ? 'picture' : 'pictures'
        ));

        return self::SUCCESS;
    }
}
