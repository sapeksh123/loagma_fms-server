<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductSalesSummaryService
{
    public function refreshForRecentDays(int $days = 60): int
    {
        $days = max(1, min($days, 3650));
        $fromDate = Carbon::today()->subDays($days - 1);
        $fromTs = $fromDate->startOfDay()->timestamp;

        DB::transaction(function () use ($fromDate, $fromTs) {
            DB::table('product_sales_daily')
                ->where('sale_date', '>=', $fromDate->toDateString())
                ->delete();

            $aggregateQuery = DB::table('orders_item as oi')
                ->join('orders as o', function ($join) {
                    $join->on('o.order_id', '=', 'oi.order_id')
                        ->orOn('o.master_order_id', '=', 'oi.order_id')
                        ->orOn('o.bill_number', '=', 'oi.order_id');
                })
                ->where('o.start_time', '>=', $fromTs)
                ->selectRaw('DATE(FROM_UNIXTIME(o.start_time)) as sale_date')
                ->selectRaw('oi.product_id')
                ->selectRaw('COUNT(DISTINCT oi.order_id) as total_orders')
                ->selectRaw('COALESCE(SUM(COALESCE(oi.qty_delivered, oi.quantity)), 0) as total_quantity')
                ->groupByRaw('DATE(FROM_UNIXTIME(o.start_time)), oi.product_id');

            DB::table('product_sales_daily')->insertUsing(
                ['sale_date', 'product_id', 'total_orders', 'total_quantity'],
                $aggregateQuery
            );
        });

        return $days;
    }
}
