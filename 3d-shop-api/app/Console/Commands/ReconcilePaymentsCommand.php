<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Payments\Checkout;
use App\Payments\PaymentGateway;
use Illuminate\Console\Command;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--minutes=15 : Only look at orders older than this}
        {--dry-run : Report and change nothing}';

    protected $description = 'Settle orders left pending because a webhook never arrived';

    /** A webhook we never received is a customer who paid and got nothing. */
    public function handle(Checkout $checkout, PaymentGateway $gateway): int
    {
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $dryRun = (bool) $this->option('dry-run');

        $orders = Order::with('items')
            ->where('status', Order::PENDING)
            ->whereNotNull('session_id')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders left waiting.');

            return self::SUCCESS;
        }

        $granted = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $status = $gateway->sessionStatus((string) $order->session_id);

            if ($status === 'paid') {
                $this->line("order {$order->id}: paid after all".($dryRun ? ' (would grant)' : ', granting'));

                if (! $dryRun) {
                    $checkout->fulfil($order);
                }

                $granted++;
                continue;
            }

            // The buyer agreed but nothing captured it, which is what a lost
            // approval notification leaves behind.
            if ($status === 'approved') {
                $this->line("order {$order->id}: approved but never captured".($dryRun ? ' (would capture)' : ', capturing'));

                if (! $dryRun && $gateway->capture((string) $order->session_id)) {
                    $checkout->fulfil($order);
                    $granted++;
                }

                continue;
            }

            if ($status === null) {
                $this->line("order {$order->id}: the gateway has never heard of it".($dryRun ? '' : ', failing'));

                if (! $dryRun) {
                    $order->update([
                        'status' => Order::FAILED,
                        'failure_reason' => 'The gateway has no record of this session.',
                    ]);
                }

                $failed++;
                continue;
            }

            $this->line("order {$order->id}: still {$status}, leaving it alone.");
        }

        $this->info(sprintf(
            '%s %d order(s), failed %d, of %d waiting.',
            $dryRun ? 'Would grant' : 'Granted',
            $granted,
            $failed,
            $orders->count()
        ));

        return self::SUCCESS;
    }
}
