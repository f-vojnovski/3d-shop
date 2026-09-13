<?php

namespace App\Console\Commands;

use App\Models\ProductFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class PruneOrphanedFilesCommand extends Command
{
    protected $signature = 'files:prune
        {--days=3 : Only delete files older than this}
        {--dry-run : List what would go and delete nothing}';

    protected $description = 'Delete stored files that no product_files row points at';

    /** Deleting a product cascades its rows; the objects behind them stay. */
    private const DISKS = ['models', 'public'];

    /**
     * Where the jobs unpack and convert. None of these is a disk, so nothing
     * else here would ever look at them, and each holds a whole copy of a
     * model: a file that reliably breaks a job leaves one behind every attempt.
     */
    private const SCRATCH = [
        'render-scratch', 'proxy-scratch', 'clip-scratch', 'custom-views',
        'custom-clips', 'bundle-read', 'convert', 'render-verify', 'remeasure',
    ];

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

        [$sweptDirs, $sweptBytes] = $this->sweepScratch($cutoff, $dryRun);

        $deleted += $sweptDirs;
        $bytes += $sweptBytes;

        $this->info(sprintf(
            '%s %d file(s), %s. %d orphan(s) too recent to touch.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $this->humanise($bytes),
            $kept
        ));

        return self::SUCCESS;
    }

    /**
     * A scratch directory is only ever alive while one job runs, so anything
     * older than the cutoff belongs to a job that is long gone.
     *
     * @return array{0: int, 1: int}
     */
    private function sweepScratch(int $cutoff, bool $dryRun): array
    {
        $swept = 0;
        $bytes = 0;

        foreach (self::SCRATCH as $name) {
            $root = storage_path('app/private/'.$name);

            if (! is_dir($root)) {
                continue;
            }

            foreach (File::directories($root) as $directory) {
                if (filemtime($directory) > $cutoff) {
                    continue;
                }

                $size = collect(File::allFiles($directory))->sum(fn ($file) => $file->getSize());

                $this->line(sprintf(
                    '%s scratch %s/%s (%d bytes)',
                    $dryRun ? 'would clear' : 'clearing',
                    $name,
                    basename($directory),
                    $size
                ));

                if (! $dryRun) {
                    File::deleteDirectory($directory);
                }

                $swept++;
                $bytes += $size;
            }
        }

        return [$swept, $bytes];
    }

    private function humanise(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
