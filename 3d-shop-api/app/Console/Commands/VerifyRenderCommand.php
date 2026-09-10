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
    protected $signature = 'render:verify {product : Product id} {--format= : Only this model format}';

    protected $description = 'Re-render a product and compare the output hashes against its stored attestations';

    public function handle(RenderRunner $runner): int
    {
        $product = Product::with('files')->find($this->argument('product'));

        if ($product === null) {
            $this->error('No such product.');

            return self::FAILURE;
        }

        $deliverables = $product->deliverables()
            ->when($this->option('format'), fn ($query, $format) => $query->where('format', $format))
            ->get();

        if ($deliverables->isEmpty()) {
            $this->error('No model file to verify.');

            return self::FAILURE;
        }

        $failures = 0;
        $verified = 0;

        foreach ($deliverables as $source) {
            $outcome = $this->verifyFormat($runner, $product, $source);

            $failures += $outcome['failures'];
            $verified += $outcome['verified'];
        }

        if ($verified === 0) {
            $this->error('This product has no attested stills to verify.');

            return self::FAILURE;
        }

        if ($failures > 0) {
            $this->error("{$failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('Every still reproduces byte-for-byte from the current model.');

        return self::SUCCESS;
    }

    /** @return array{failures: int, verified: int} */
    private function verifyFormat(RenderRunner $runner, Product $product, ProductFile $source): array
    {
        $stored = $source->stills()->get()->keyBy('sort');

        if ($stored->isEmpty()) {
            $this->line(".{$source->format}: no stills recorded, skipped.");

            return ['failures' => 0, 'verified' => 0];
        }

        $scratch = storage_path('app/private/render-verify/'.$product->id.'-'.$source->format);
        File::deleteDirectory($scratch);
        File::makeDirectory($scratch, 0775, true);

        $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
        $bytes = RenderInput::fetch($source, $modelPath);
        $this->line(".{$source->format}: fetched {$bytes} bytes from the {$source->disk} disk.");

        $result = $runner->run(
            RenderInput::request($product, $source, RenderInput::scanOf($source)),
            $modelPath,
            $scratch
        );

        if (($result['status'] ?? 'failed') !== 'ok') {
            $this->error(".{$source->format}: re-render failed: ".($result['reason'] ?? 'unknown'));

            return ['failures' => 1, 'verified' => 0];
        }

        $rows = [];
        $failures = 0;

        foreach ($result['images'] as $image) {
            $produced = hash_file(
                'sha256',
                $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$image['file']
            );
            $record = $stored->get($image['index']);
            $matches = $record !== null && hash_equals($record->checksum, $produced);
            $failures += $matches ? 0 : 1;

            $rows[] = [
                ".{$source->format}",
                $image['index'],
                substr((string) $record?->checksum, 0, 16).'…',
                substr($produced, 0, 16).'…',
                $matches ? 'match' : 'MISMATCH',
            ];
        }

        $this->table(['format', 'angle', 'attested', 're-rendered', ''], $rows);

        // A swapped model leaves the stills intact but no longer depicting what
        // is for sale, which the pixel hashes alone would not reveal.
        $attestedSource = $stored->first()->meta['source_checksum'] ?? null;

        if ($attestedSource !== null && ! hash_equals($attestedSource, (string) $source->checksum)) {
            $this->error(".{$source->format}: the current model is not the one these stills came from.");
            $failures++;
        }

        if ($failures > 0) {
            $this->error(".{$source->format}: scratch kept at {$scratch}");
        } else {
            File::deleteDirectory($scratch);
        }

        return ['failures' => $failures, 'verified' => count($result['images'])];
    }
}
