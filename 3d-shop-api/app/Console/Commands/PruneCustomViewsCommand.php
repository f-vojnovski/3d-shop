<?php

namespace App\Console\Commands;

use App\Models\CustomView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCustomViewsCommand extends Command
{
    protected $signature = 'views:prune {--dry-run : List what would go and delete nothing}';

    protected $description = 'Delete viewer-requested renders whose two hours are up';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $bytes = 0;

        foreach (CustomView::expired()->cursor() as $view) {
            $this->line(($dryRun ? 'would delete ' : 'deleting ')."custom view #{$view->id} ({$view->bytes} bytes)");

            if (! $dryRun) {
                // The object first: a row left behind is swept again next hour,
                // an object with no row is only found by files:prune.
                if ($view->path !== null) {
                    Storage::disk($view->disk ?? 'public')->delete($view->path);
                }

                $view->delete();
            }

            $deleted++;
            $bytes += (int) $view->bytes;
        }

        $this->info(sprintf(
            '%s %d view(s), %s KB.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            number_format($bytes / 1024, 1)
        ));

        return self::SUCCESS;
    }
}
