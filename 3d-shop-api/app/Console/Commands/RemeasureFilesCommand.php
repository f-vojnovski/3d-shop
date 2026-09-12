<?php

namespace App\Console\Commands;

use App\Models\ProductFile;
use App\Support\MeshFacts;
use App\Support\RenderInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measuring happens once, at upload, so a listing keeps whatever the reader
 * could work out on the day it arrived. When the reader learns something new —
 * a texture size it used to miss, a rig it used to report as a bare yes — the
 * files already sold keep the old answer until someone asks for it again.
 *
 * Nothing here re-renders or re-publishes. It replaces one field of one record.
 */
class RemeasureFilesCommand extends Command
{
    protected $signature = 'models:remeasure
        {--product= : Only this product}
        {--missing-only : Skip files that already carry the newer measurements}
        {--dry-run : Say what would change and change nothing}';

    protected $description = 'Read the facts out of uploaded models again, with the current reader.';

    public function handle(): int
    {
        $files = ProductFile::query()
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->whereNull('superseded_at')
            ->when($this->option('product'), fn ($query, $id) => $query->where('product_id', $id))
            ->orderBy('id')
            ->get();

        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            if ($this->option('missing-only') && $this->alreadyCurrent($file)) {
                $skipped++;

                continue;
            }

            try {
                $facts = $this->measure($file);
            } catch (Throwable $exception) {
                $this->warn("#{$file->id} .{$file->format}: {$exception->getMessage()}");
                Log::warning('Could not re-measure a model.', [
                    'product_file_id' => $file->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $failed++;

                continue;
            }

            if ($facts === null) {
                $skipped++;

                continue;
            }

            $before = $file->facts() ?? [];

            if ($facts === $before) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '#%d .%s  textures %d → %d   rig %s → %s',
                $file->id,
                $file->format,
                count($before['textures'] ?? []),
                count($facts['textures'] ?? []),
                isset($before['rig']) ? 'read' : 'none',
                isset($facts['rig']) ? 'read' : 'none',
            ));

            if (! $this->option('dry-run')) {
                $file->withMeta(['facts' => $facts]);
            }

            $changed++;
        }

        $this->info(sprintf(
            '%s %d of %d, skipped %d, failed %d.',
            $this->option('dry-run') ? 'Would change' : 'Measured',
            $changed,
            $files->count(),
            $skipped,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function measure(ProductFile $file): ?array
    {
        $scratch = storage_path('app/private/remeasure/'.$file->id);

        File::deleteDirectory($scratch);
        File::ensureDirectoryExists($scratch, 0775, true);

        try {
            $path = $scratch.DIRECTORY_SEPARATOR.'model';
            RenderInput::fetch($file, $path);

            $scan = RenderInput::scanOf($file);
            $root = null;

            if (RenderInput::isBundle($file)) {
                $unpacked = RenderInput::unpack($path, $scratch);

                if (is_string($unpacked)) {
                    return null;
                }

                [$root, $entry] = [$unpacked['dir'], $unpacked['entry']];
                $path = $root.DIRECTORY_SEPARATOR.$entry;
            }

            return MeshFacts::of($path, $scan->format, $root)->toArray();
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /** The rig block is the newest thing the reader learned, so it dates a record. */
    private function alreadyCurrent(ProductFile $file): bool
    {
        return array_key_exists('rig', $file->facts() ?? []);
    }
}
