<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductFile;
use App\Support\RenderInput;
use App\Support\RenderRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifyRenderCommand extends Command
{
    protected $signature = 'render:verify {product : Product id}';

    protected $description = 'Re-render a product and compare the output hashes against its stored attestations';

    public function handle(RenderRunner $runner): int
    {
        $product = Product::with('files')->find($this->argument('product'));

        if ($product === null) {
            $this->error('No such product.');

            return self::FAILURE;
        }

        $source = $product->files->firstWhere('kind', ProductFile::KIND_DELIVERABLE);
        $stored = $product->files
            ->where('kind', ProductFile::KIND_PREVIEW_IMAGE)
            ->sortBy('sort')
            ->keyBy('sort');

        if ($source === null || $stored->isEmpty()) {
            $this->error('This product has no attested stills to verify.');

            return self::FAILURE;
        }

        $scratch = storage_path('app/private/render-verify/'.$product->id);
        File::deleteDirectory($scratch);
        File::makeDirectory($scratch, 0775, true);

        $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
        $bytes = RenderInput::fetch($source, $modelPath);
        $this->line("Fetched {$bytes} bytes from the {$source->disk} disk.");

        $result = $runner->run(
            RenderInput::request($product, $source, RenderInput::scanOf($source)),
            $modelPath,
            $scratch
        );

        if (($result['status'] ?? 'failed') !== 'ok') {
            $this->error('Re-render failed: '.($result['reason'] ?? 'unknown'));

            return self::FAILURE;
        }

        $rows = [];
        $mismatches = 0;

        foreach ($result['images'] as $image) {
            $produced = hash_file(
                'sha256',
                $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$image['file']
            );
            $record = $stored->get($image['index']);
            $matches = $record !== null && hash_equals($record->checksum, $produced);
            $mismatches += $matches ? 0 : 1;

            $rows[] = [
                $image['index'],
                substr((string) $record?->checksum, 0, 16).'…',
                substr($produced, 0, 16).'…',
                $matches ? 'match' : 'MISMATCH',
            ];
        }

        $this->table(['angle', 'attested', 're-rendered', ''], $rows);

        // A swapped model leaves the stills intact but no longer depicting what
        // is for sale, which the pixel hashes alone would not reveal.
        $attestedSource = $stored->first()->meta['source_checksum'] ?? null;

        if ($attestedSource !== null && ! hash_equals($attestedSource, (string) $source->checksum)) {
            $this->error('The current model is not the one these stills were rendered from.');
            $mismatches++;
        }

        if ($mismatches > 0) {
            $this->error("{$mismatches} check(s) failed. Scratch kept at {$scratch}");

            return self::FAILURE;
        }

        File::deleteDirectory($scratch);
        $this->info('Every still reproduces byte-for-byte from the current model.');

        return self::SUCCESS;
    }
}
