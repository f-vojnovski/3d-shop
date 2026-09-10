<?php

namespace App\Console\Commands;

use App\Models\ProductFile;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class AuditStoredFilesCommand extends Command
{
    protected $signature = 'files:audit
        {--product= : Limit the audit to one product}
        {--kind= : Limit to deliverable, preview_image, thumbnail or seller_image}';

    protected $description = 'Re-hash every stored file and compare it to the checksum recorded for it';

    /**
     * The checksum column is written once, at upload. Every claim this project
     * makes about a preview depending on a particular model rests on the bytes
     * behind that row still being the bytes that were hashed, and nothing
     * except reading them back can establish that.
     */
    public function handle(): int
    {
        $files = ProductFile::query()
            ->when($this->option('product'), fn ($query, $id) => $query->where('product_id', $id))
            ->when($this->option('kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->whereNotNull('checksum')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        if ($files->isEmpty()) {
            $this->info('Nothing to audit.');

            return self::SUCCESS;
        }

        $missing = 0;
        $altered = 0;

        foreach ($files as $file) {
            $disk = Storage::disk($file->disk);

            if (! $disk->exists($file->path)) {
                $this->error("product {$file->product_id} {$file->kind} #{$file->id}: gone from {$file->disk}:{$file->path}");
                $missing++;
                continue;
            }

            $found = $this->hashOf($disk, $file->path);

            if ($found === null) {
                $this->error("product {$file->product_id} {$file->kind} #{$file->id}: could not be read from {$file->disk}.");
                $missing++;
                continue;
            }

            if (! hash_equals((string) $file->checksum, $found)) {
                $this->error(sprintf(
                    'product %d %s #%d: recorded %s…, stored %s…',
                    $file->product_id,
                    $file->kind,
                    $file->id,
                    substr((string) $file->checksum, 0, 16),
                    substr($found, 0, 16)
                ));
                $altered++;
                continue;
            }

            $this->line("product {$file->product_id} {$file->kind} #{$file->id}: matches.");
        }

        if ($missing > 0 || $altered > 0) {
            $this->error(sprintf(
                '%d file(s) altered, %d missing, out of %d.',
                $altered,
                $missing,
                $files->count()
            ));

            return self::FAILURE;
        }

        $this->info("All {$files->count()} stored file(s) match the checksums recorded for them.");

        return self::SUCCESS;
    }

    /** Streamed: a deliverable can be 600 MB, and this runs over all of them. */
    private function hashOf(Filesystem $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);

        if ($stream === null || $stream === false) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
