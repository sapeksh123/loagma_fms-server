<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\OrderItemsReconciliationService;
use App\Services\ProductSalesSummaryService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:refresh-product-sales-summary {--days=60}', function () {
    $days = (int) $this->option('days');
    $service = app(ProductSalesSummaryService::class);
    $refreshed = $service->refreshForRecentDays($days);

    $this->info("Refreshed product sales daily summary for {$refreshed} day(s).");
})->purpose('Refresh product sales daily summary table');

Artisan::command('orders:reconcile-items {--since-order-id=245327} {--limit=200} {--dry-run}', function () {
    $sinceOrderId = (int) $this->option('since-order-id');
    $limit = (int) $this->option('limit');
    $dryRun = (bool) $this->option('dry-run');

    $service = app(OrderItemsReconciliationService::class);
    $result = $service->reconcileMissingOrders($sinceOrderId, $limit, $dryRun);

    $this->info('Order items reconciliation result:');
    $this->line(json_encode($result, JSON_PRETTY_PRINT));
})->purpose('Backfill recoverable orders_item rows for missing orders');

Schedule::command('reports:refresh-product-sales-summary --days=60')->everyFifteenMinutes();
Schedule::command('orders:reconcile-items --since-order-id=245327 --limit=300')->everyTenMinutes()->withoutOverlapping();
