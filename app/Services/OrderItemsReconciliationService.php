<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderItemsReconciliationService
{
    public function getMissingOrders(int $sinceOrderId = 245327, int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));

        $rows = DB::table('orders as o')
            ->where('o.order_id', '>', $sinceOrderId)
            ->where('o.items_count', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('orders_item as oi')
                    ->whereColumn('oi.order_id', 'o.order_id');
            })
            ->orderByDesc('o.order_id')
            ->limit($limit)
            ->get([
                'o.order_id',
                'o.master_order_id',
                'o.bill_number',
                'o.txn_id',
                'o.items_count',
                'o.order_state',
                'o.start_time',
            ]);

        return $rows->map(fn($row) => (array) $row)->all();
    }

    public function countMissingOrders(int $sinceOrderId = 245327): int
    {
        return DB::table('orders as o')
            ->where('o.order_id', '>', $sinceOrderId)
            ->where('o.items_count', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('orders_item as oi')
                    ->whereColumn('oi.order_id', 'o.order_id');
            })
            ->count();
    }

    public function reconcileMissingOrders(int $sinceOrderId = 245327, int $limit = 200, bool $dryRun = false): array
    {
        $targets = $this->getMissingOrders($sinceOrderId, $limit);
        $stats = [
            'dry_run' => $dryRun,
            'scanned' => count($targets),
            'recovered' => 0,
            'skipped_already_present' => 0,
            'unresolved' => 0,
            'inserted_rows' => 0,
            'details' => [],
        ];

        foreach ($targets as $target) {
            $orderId = (int) ($target['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $alreadyCount = DB::table('orders_item')->where('order_id', $orderId)->count();
            if ($alreadyCount > 0) {
                $stats['skipped_already_present']++;
                continue;
            }

            $sourceRows = $this->findRecoverableSourceRows($target);
            if (empty($sourceRows)) {
                $stats['unresolved']++;
                $stats['details'][] = [
                    'order_id' => $orderId,
                    'status' => 'unresolved',
                    'reason' => 'No recoverable source rows found from related orders',
                ];
                continue;
            }

            if ($dryRun) {
                $stats['recovered']++;
                $stats['inserted_rows'] += count($sourceRows);
                $stats['details'][] = [
                    'order_id' => $orderId,
                    'status' => 'recoverable',
                    'source_rows' => count($sourceRows),
                ];
                continue;
            }

            $inserted = $this->insertRecoveredRows($orderId, $sourceRows);
            if ($inserted > 0) {
                $stats['recovered']++;
                $stats['inserted_rows'] += $inserted;
                $stats['details'][] = [
                    'order_id' => $orderId,
                    'status' => 'recovered',
                    'inserted_rows' => $inserted,
                ];
            } else {
                $stats['unresolved']++;
                $stats['details'][] = [
                    'order_id' => $orderId,
                    'status' => 'unresolved',
                    'reason' => 'Insert produced no rows',
                ];
            }
        }

        return $stats;
    }

    private function findRecoverableSourceRows(array $target): array
    {
        $targetOrderId = (int) ($target['order_id'] ?? 0);
        $masterOrderId = isset($target['master_order_id']) ? (int) $target['master_order_id'] : 0;
        $billNumber = isset($target['bill_number']) ? (int) $target['bill_number'] : 0;
        $txnId = trim((string) ($target['txn_id'] ?? ''));

        $candidateOrderIds = DB::table('orders')
            ->where('order_id', '!=', $targetOrderId)
            ->where(function ($q) use ($masterOrderId, $billNumber, $txnId) {
                if ($masterOrderId > 0) {
                    $q->orWhere('master_order_id', $masterOrderId)
                        ->orWhere('order_id', $masterOrderId);
                }

                if ($billNumber > 0) {
                    $q->orWhere('bill_number', $billNumber)
                        ->orWhere('order_id', $billNumber);
                }

                if ($txnId !== '') {
                    $q->orWhere('txn_id', $txnId);
                }
            })
            ->pluck('order_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($candidateOrderIds)) {
            return [];
        }

        $sourceOrder = DB::table('orders_item')
            ->select('order_id', DB::raw('COUNT(*) as row_count'))
            ->whereIn('order_id', $candidateOrderIds)
            ->groupBy('order_id')
            ->orderByDesc('row_count')
            ->first();

        if (!$sourceOrder) {
            return [];
        }

        $sourceOrderId = (int) ($sourceOrder->order_id ?? 0);
        if ($sourceOrderId <= 0) {
            return [];
        }

        return DB::table('orders_item')
            ->where('order_id', $sourceOrderId)
            ->get()
            ->map(fn($row) => (array) $row)
            ->all();
    }

    private function insertRecoveredRows(int $targetOrderId, array $sourceRows): int
    {
        return DB::transaction(function () use ($targetOrderId, $sourceRows) {
            $nextItemId = (int) (DB::table('orders_item')->max('item_id') ?? 0) + 1;
            $inserted = 0;

            foreach ($sourceRows as $row) {
                $payload = [
                    'order_id' => $targetOrderId,
                    'item_id' => $nextItemId++,
                    'product_id' => (int) ($row['product_id'] ?? 0),
                    'vendor_product_id' => $row['vendor_product_id'] ?? null,
                    'pinfo' => (string) ($row['pinfo'] ?? ''),
                    'offers' => $row['offers'] ?? null,
                    'quantity' => (int) ($row['quantity'] ?? 0),
                    'qty_loaded' => $row['qty_loaded'] ?? null,
                    'qty_delivered' => $row['qty_delivered'] ?? null,
                    'qty_returned' => $row['qty_returned'] ?? null,
                    'item_price' => (float) ($row['item_price'] ?? 0),
                    'item_total' => (float) ($row['item_total'] ?? 0),
                    'op_id' => $row['op_id'] ?? 0,
                    'commission' => (float) ($row['commission'] ?? 0),
                ];

                try {
                    DB::table('orders_item')->insert($payload);
                    $inserted++;
                } catch (\Throwable $e) {
                    Log::warning('Order item backfill insert skipped', [
                        'order_id' => $targetOrderId,
                        'product_id' => $payload['product_id'],
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $inserted;
        });
    }
}
