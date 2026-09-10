<?php

namespace App\Console\Commands;

use App\Models\ProductFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanedFilesCommand extends Command
{
    protected $signature = 'files:prune
        {--days=3 : Only delete files older than this}
        {--dry-run : List what would go and delete nothing}';

    protected $description = 'Delete stored files that no product_files row points at';

    /** Deleting a product cascades its rows; the objects behind them stay. */
    private const DISKS = ['models', 'public'];

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->getTimestamp();

        $referenced = ProductFile::query()
            ->select('disk', 'path')
            ->get()
            ->groupBy('disk')
            ->map(fn ($rows) => $rows->pluck('path')->flip());

        $deleted = 0;
        $bytes = 0;
        $kept = 0;

        foreach (self::DISKS as $name) {
            $disk = Storage::disk($name);
            $inUse = $referenced->get($name) ?? collect();

            foreach ($disk->allFiles() as $path) {
                if ($inUse->has($path)) {
                    continue;
                }

                // A file uploaded seconds ago may not have its row yet.
                if ($disk->lastModified($path) > $cutoff) {
                    $kept++;
                    continue;
                }

                $size = $disk->size($path);
                $this->line(($dryRun ? 'would delete ' : 'deleting ').$name.':'.$path.' ('.$size.' bytes)');

                if (! $dryRun) {
                    $disk->delete($path);
                }

                $deleted++;
                $bytes += $size;
            }
        }

        $this->info(sprintf(
            '%s %d file(s), %s. %d orphan(s) too recent to touch.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $this->humanise($bytes),
            $kept
        ));

        return self::SUCCESS;
    }

    private function humanise(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
