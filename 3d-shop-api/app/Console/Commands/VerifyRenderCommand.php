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

        // Failures first: a format that refused before it could render leaves
        // nothing verified, and "nothing to verify" is the wrong diagnosis.
        if ($failures > 0) {
            $this->error("{$failures} check(s) failed.");

            return self::FAILURE;
        }

        if ($verified === 0) {
            $this->error('This product has no attested images to verify.');

            return self::FAILURE;
        }

        $this->info('Every attested image reproduces byte-for-byte from the current model.');

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

        // What the renderer opened is what the pixels came from, and for a
        // converted upload that is not the file the buyer downloads.
        $derived = $source->derived()->current()->first();
        $opened = $derived ?? $source;

        $modelPath = $scratch.DIRECTORY_SEPARATOR.'model';
        $bytes = RenderInput::fetch($opened, $modelPath);
        $this->line(sprintf(
            '.%s: fetched %d bytes from the %s disk%s.',
            $source->format,
            $bytes,
            $opened->disk,
            $derived === null ? '' : ' (converted to glb)'
        ));

        // Named here rather than left to show up as mismatched pixels: if the
        // stored bytes changed, every still is describing a file that is gone.
        $fetched = (string) hash_file('sha256', $modelPath);

        if (! hash_equals((string) $opened->checksum, $fetched)) {
            $this->error(sprintf(
                '.%s: the stored file is not the one that was recorded (%s… on disk, %s… recorded).',
                $source->format,
                substr($fetched, 0, 16),
                substr((string) $opened->checksum, 0, 16)
            ));

            return ['failures' => 1, 'verified' => 0];
        }

        $result = $runner->run(
            RenderInput::request(
                $product,
                $source,
                RenderInput::scanOf($source),
                $derived === null ? null : 'glb'
            ),
            $modelPath,
            $scratch
        );

        if (($result['status'] ?? 'failed') !== 'ok') {
            $this->error(".{$source->format}: re-render failed: ".($result['reason'] ?? 'unknown'));

            return ['failures' => 1, 'verified' => 0];
        }

        $outlines = $source->wireframes()->get()->keyBy('sort');
        $rows = [];
        $failures = 0;
        $checked = 0;

        foreach ($result['images'] as $image) {
            $passes = [['shaded', $stored->get($image['index']), $image['file']]];

            // A product rendered before this pass existed has nothing to compare.
            if ($outlines->has($image['index'])) {
                $passes[] = [
                    'wireframe',
                    $outlines->get($image['index']),
                    $image['wireframe']['file'] ?? null,
                ];
            }

            foreach ($passes as [$pass, $record, $file]) {
                $produced = $file === null ? null : hash_file(
                    'sha256',
                    $scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.$file
                );
                $matches = $record !== null
                    && $produced !== false
                    && $produced !== null
                    && hash_equals($record->checksum, $produced);
                $failures += $matches ? 0 : 1;
                $checked++;

                $rows[] = [
                    ".{$source->format}",
                    $image['index'],
                    $pass,
                    substr((string) $record?->checksum, 0, 16).'…',
                    $produced === null || $produced === false
                        ? 'not drawn'
                        : substr($produced, 0, 16).'…',
                    $matches ? 'match' : 'MISMATCH',
                ];
            }
        }

        $this->table(['format', 'angle', 'pass', 'attested', 're-rendered', ''], $rows);

        // Every hash it did produce would still match, so count as well.
        $missing = $stored->count() + $outlines->count() - $checked;

        if ($missing > 0) {
            $this->error(sprintf(
                '.%s: %d attested image(s) were not re-produced at all.',
                $source->format,
                $missing
            ));
            $failures += $missing;
        }

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

        return ['failures' => $failures, 'verified' => $checked];
    }
}
