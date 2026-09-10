<?php

namespace App\Console\Commands;

use App\Models\ProductFile;
use Illuminate\Console\Command;

class ReapStalledRendersCommand extends Command
{
    protected $signature = 'renders:reap
        {--minutes=25 : How long a render may sit before it is called dead}
        {--dry-run : List what would be failed and change nothing}';

    protected $description = 'Fail renders left mid-flight by a worker that died without reporting';

    /**
     * The job's failed() hook only runs when the queue reports a failure, so a
     * SIGKILLed worker leaves its format on 'rendering' forever. The row's
     * updated_at is when it entered that state.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');

        $stalled = ProductFile::query()
            ->where('kind', ProductFile::KIND_DELIVERABLE)
            ->whereIn('meta->render->status', ['queued', 'rendering'])
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($stalled->isEmpty()) {
            $this->info('Nothing stalled.');

            return self::SUCCESS;
        }

        foreach ($stalled as $file) {
            $this->line(sprintf(
                '%sproduct %d .%s has been %s since %s',
                $dryRun ? 'would fail: ' : 'failing: ',
                $file->product_id,
                $file->format,
                $file->renderStatus(),
                $file->updated_at->diffForHumans()
            ));

            if ($dryRun) {
                continue;
            }

            $file->withMeta(['render' => [
                'status' => 'failed',
                'error' => 'The render stopped without reporting. Upload the product again to retry.',
            ]]);

            $file->product?->refreshPreviewStatus();
        }

        $this->info(($dryRun ? 'Would fail ' : 'Failed ').$stalled->count().' stalled render(s).');

        return self::SUCCESS;
    }
}
