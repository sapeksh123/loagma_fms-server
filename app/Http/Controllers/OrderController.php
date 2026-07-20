<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderItemsReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function missingItemsHealth(Request $request, OrderItemsReconciliationService $service)
    {
        try {
            $sinceOrderId = max((int) $request->get('since_order_id', 245327), 0);
            $limit = max((int) $request->get('limit', 50), 1);
            $limit = min($limit, 500);

            $missingCount = $service->countMissingOrders($sinceOrderId);
            $latestMissing = $service->getMissingOrders($sinceOrderId, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'since_order_id' => $sinceOrderId,
                    'missing_orders_count' => $missingCount,
                    'latest_missing_orders' => $latestMissing,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            if (!\Schema::hasTable('orders')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'per_page' => 20,
                        'current_page' => 1,
                        'last_page' => 1,
                        'has_more' => false,
                    ],
                    'message' => 'Orders table not configured yet',
                ]);
            }

            $perPage = min(max((int) $request->get('per_page', 20), 1), 500);
            $page = max((int) $request->get('page', 1), 1);
            $search = trim((string) $request->get('search', ''));
            $status = strtolower(trim((string) $request->get('status', '')));
            $dayFilter = strtolower(trim((string) $request->get('day_filter', '')));
            $fromDate = trim((string) $request->get('from_date', ''));
            $toDate = trim((string) $request->get('to_date', ''));
            $fromTsParam = trim((string) $request->get('from_ts', ''));
            $toTsParam = trim((string) $request->get('to_ts', ''));
            $minItemCountParam = trim((string) $request->get('min_item_count', ''));
            $maxItemCountParam = trim((string) $request->get('max_item_count', ''));
            $buyerUserIdParam = trim((string) $request->get('buyer_userid', ''));

            $query = Order::query();

            $resolvedMinItemCount = null;
            $resolvedMaxItemCount = null;

            if ($minItemCountParam !== '') {
                if (!ctype_digit($minItemCountParam)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'min_item_count must be a positive integer',
                    ], 422);
                }
                $resolvedMinItemCount = (int) $minItemCountParam;
            }

            if ($maxItemCountParam !== '') {
                if (!ctype_digit($maxItemCountParam)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'max_item_count must be a positive integer',
                    ], 422);
                }
                $resolvedMaxItemCount = (int) $maxItemCountParam;
            }

            if ($resolvedMinItemCount !== null && $resolvedMaxItemCount === null) {
                $resolvedMaxItemCount = 500;
            }

            if ($resolvedMaxItemCount !== null && $resolvedMinItemCount === null) {
                $resolvedMinItemCount = 1;
            }

            if ($resolvedMinItemCount !== null) {
                $resolvedMinItemCount = max(1, min($resolvedMinItemCount, 500));
            }

            if ($resolvedMaxItemCount !== null) {
                $resolvedMaxItemCount = max(1, min($resolvedMaxItemCount, 500));
            }

            if (
                $resolvedMinItemCount !== null &&
                $resolvedMaxItemCount !== null &&
                $resolvedMinItemCount > $resolvedMaxItemCount
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'min_item_count cannot be greater than max_item_count',
                ], 422);
            }

            if ($search !== '') {
                $searchLower = strtolower($search);
                $safeLike = '%' . $this->escapeLike($searchLower) . '%';

                $query->where(function ($q) use ($search, $safeLike) {
                    if (is_numeric($search)) {
                        $q->orWhere('order_id', (int) $search);
                    }

                    // Keep search simple and stable across customer name/address/contact
                    // in serialized delivery_info JSON.
                    $q->orWhereRaw("LOWER(delivery_info) LIKE ? ESCAPE '\\\\'", [$safeLike]);
                });
            }

            if ($status !== '' && $status !== 'all') {
                if ($status === 'pending') {
                    $query->whereIn('order_state', ['registered', 'pending']);
                } else {
                    $query->whereRaw('LOWER(order_state) = ?', [$status]);
                }
            }

            if ($buyerUserIdParam !== '') {
                if (!ctype_digit($buyerUserIdParam)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'buyer_userid must be a positive integer',
                    ], 422);
                }
                $query->where('buyer_userid', (int) $buyerUserIdParam);
            }

            $fromTs = null;
            $toTs = null;

            if ($dayFilter === 'today') {
                $fromTs = Carbon::now()->startOfDay()->timestamp;
                $toTs = Carbon::now()->endOfDay()->timestamp;
            } elseif ($dayFilter === 'yesterday') {
                $fromTs = Carbon::yesterday()->startOfDay()->timestamp;
                $toTs = Carbon::yesterday()->endOfDay()->timestamp;
            } elseif ($dayFilter === '2days') {
                $fromTs = Carbon::now()->subDays(1)->startOfDay()->timestamp;
                $toTs = Carbon::now()->endOfDay()->timestamp;
            } elseif ($dayFilter === 'last7') {
                $fromTs = Carbon::now()->subDays(6)->startOfDay()->timestamp;
                $toTs = Carbon::now()->endOfDay()->timestamp;
            }

            if ($fromTsParam !== '' && ctype_digit($fromTsParam)) {
                $fromTs = (int) $fromTsParam;
            }

            if ($toTsParam !== '' && ctype_digit($toTsParam)) {
                $toTs = (int) $toTsParam;
            }

            if ($fromDate !== '') {
                $parsedFromTs = $this->parseDateToUnix($fromDate, true);
                if ($parsedFromTs === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid from_date format. Use Y-m-d, d/m/Y, or d-m-Y',
                    ], 422);
                }
                $fromTs = $parsedFromTs;
            }

            if ($toDate !== '') {
                $parsedToTs = $this->parseDateToUnix($toDate, false);
                if ($parsedToTs === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid to_date format. Use Y-m-d, d/m/Y, or d-m-Y',
                    ], 422);
                }
                $toTs = $parsedToTs;
            }

            if ($fromTs !== null && $toTs !== null) {
                $query->whereBetween('start_time', [$fromTs, $toTs]);
            } elseif ($fromTs !== null) {
                $query->where('start_time', '>=', $fromTs);
            } elseif ($toTs !== null) {
                $query->where('start_time', '<=', $toTs);
            }

            if ($resolvedMinItemCount !== null && $resolvedMaxItemCount !== null) {
                $query->whereBetween('items_count', [$resolvedMinItemCount, $resolvedMaxItemCount]);
            }

            $total = $query->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $orders = $query
                ->orderByDesc('order_id')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $resolvedItemsCountByOrderId = $this->resolveItemsCountForOrders($orders);

            $adminNameById = $this->getAdminNamesByIds(
                $orders->pluck('admin_id')->filter()->map(fn($v) => (int) $v)->unique()->values()->all()
            );
            $shopNameByBuyerId = $this->getShopNamesByBuyerIds(
                $orders->pluck('buyer_userid')->filter()->map(fn($v) => (int) $v)->unique()->values()->all()
            );

            $data = $orders->map(function ($order) use ($adminNameById, $shopNameByBuyerId) {
                $deliveryInfo = $this->decodeDeliveryInfo($order->delivery_info);
                $driver = $this->resolveDriverDetails($order, $deliveryInfo);
                $resolvedItemsCount = $resolvedItemsCountByOrderId[(int) $order->order_id] ?? (int) $order->items_count;

                return [
                    'order_id' => (int) $order->order_id,
                    'master_order_id' => (int) $order->master_order_id,
                    'buyer_userid' => (int) $order->buyer_userid,
                    'admin_id' => (int) $order->admin_id,
                    'admin_name' => $adminNameById[(int) $order->admin_id] ?? '',
                    'shop_name' => $shopNameByBuyerId[(int) $order->buyer_userid] ?? '',
                    'customer_name' => $deliveryInfo['name'] ?? ('User #' . $order->buyer_userid),
                    'customer_address' => $deliveryInfo['address'] ?? $order->area_name,
                    'area_name' => (string) $order->area_name,
                    'customer_contact' => $deliveryInfo['contactno'] ?? '',
                    'status' => (string) $order->order_state,
                    'payment_status' => (string) $order->payment_status,
                    'payment_method' => (string) $order->payment_method,
                    'items_count' => $resolvedItemsCount,
                    'amount' => (float) $order->order_total,
                    'delivery_charge' => (float) $order->delivery_charge,
                    'discount' => (float) $order->discount,
                    'time_slot' => (string) $order->time_slot,
                    'short_datetime' => (string) $order->short_datetime,
                    'created_unix' => (int) $order->start_time,
                    'updated_unix' => (int) $order->last_update_time,
                    'delivered_unix' => $order->delivered_time ? (int) $order->delivered_time : null,
                    'delivery_latitude' => isset($deliveryInfo['latitude']) ? (float) $deliveryInfo['latitude'] : null,
                    'delivery_longitude' => isset($deliveryInfo['longitude']) ? (float) $deliveryInfo['longitude'] : null,
                    'is_express_delivery' => $this->toBool($deliveryInfo['expressDelivery'] ?? $deliveryInfo['express_delivery'] ?? null),
                    'driver_name' => $driver['name'],
                    'driver_contact' => $driver['contact'],
                    'deli_id' => isset($order->deli_id) ? (int) $order->deli_id : null,
                    'can_call' => !empty($deliveryInfo['contactno']),
                    'can_locate' => isset($deliveryInfo['latitude']) && isset($deliveryInfo['longitude']),
                    'can_print' => true,
                ];
            })->values();

            $payload = [
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $data->count(), $total),
                    'has_more' => $page < $lastPage,
                ],
            ];

            return response()->json($this->sanitizeForJson($payload));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $order = Order::query()->where('order_id', $id)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $deliveryInfo = $this->decodeDeliveryInfo($order->delivery_info);
            $items = $this->getOrderItems($order);
            $resolvedItemsCount = !empty($items) ? count($items) : (int) $order->items_count;
            $adminNameById = $this->getAdminNamesByIds([(int) $order->admin_id]);
            $shopNameByBuyerId = $this->getShopNamesByBuyerIds([(int) $order->buyer_userid]);
            $driver = $this->resolveDriverDetails($order, $deliveryInfo);

            $payload = [
                'success' => true,
                'data' => [
                    'order_id' => (int) $order->order_id,
                    'master_order_id' => (int) $order->master_order_id,
                    'txn_id' => (string) $order->txn_id,
                    'buyer_userid' => (int) $order->buyer_userid,
                    'admin_id' => (int) $order->admin_id,
                    'admin_name' => $adminNameById[(int) $order->admin_id] ?? '',
                    'shop_name' => $shopNameByBuyerId[(int) $order->buyer_userid] ?? '',
                    'customer_name' => $deliveryInfo['name'] ?? ('User #' . $order->buyer_userid),
                    'customer_address' => $deliveryInfo['address'] ?? $order->area_name,
                    'area_name' => (string) $order->area_name,
                    'customer_contact' => $deliveryInfo['contactno'] ?? '',
                    'delivery_info' => $deliveryInfo,
                    'is_express_delivery' => $this->toBool($deliveryInfo['expressDelivery'] ?? $deliveryInfo['express_delivery'] ?? null),
                    'driver_name' => $driver['name'],
                    'driver_contact' => $driver['contact'],
                    'deli_id' => isset($order->deli_id) ? (int) $order->deli_id : null,
                    'status' => (string) $order->order_state,
                    'payment_status' => (string) $order->payment_status,
                    'payment_method' => (string) $order->payment_method,
                    'items_count' => $resolvedItemsCount,
                    'amount' => (float) $order->order_total,
                    'delivery_charge' => (float) $order->delivery_charge,
                    'discount' => (float) $order->discount,
                    'before_discount' => (float) $order->before_discount,
                    'bill_amount' => $order->bill_amount,
                    'time_slot' => (string) $order->time_slot,
                    'short_datetime' => (string) $order->short_datetime,
                    'created_unix' => (int) $order->start_time,
                    'updated_unix' => (int) $order->last_update_time,
                    'delivered_unix' => $order->delivered_time ? (int) $order->delivered_time : null,
                    'items' => $items,
                ],
            ];

            return response()->json($this->sanitizeForJson($payload));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function customerOrderHistory(Request $request, $buyerUserId)
    {
        if (!ctype_digit((string) $buyerUserId) || (int) $buyerUserId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'buyer_userid must be a positive integer',
            ], 422);
        }

        try {
            $resolvedBuyerUserId = (int) $buyerUserId;
            $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
            $page = max((int) $request->get('page', 1), 1);

            $range = $this->resolveDateRangeFromRequest($request);
            if (isset($range['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $range['error'],
                ], 422);
            }

            $query = Order::query()->where('buyer_userid', $resolvedBuyerUserId);

            if ($range['fromTs'] !== null && $range['toTs'] !== null) {
                $query->whereBetween('start_time', [$range['fromTs'], $range['toTs']]);
            } elseif ($range['fromTs'] !== null) {
                $query->where('start_time', '>=', $range['fromTs']);
            } elseif ($range['toTs'] !== null) {
                $query->where('start_time', '<=', $range['toTs']);
            }

            $total = $query->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $orders = $query
                ->orderByDesc('order_id')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $resolvedItemsCountByOrderId = $this->resolveItemsCountForOrders($orders);

            $data = $orders->map(function ($order) use ($resolvedItemsCountByOrderId) {
                $resolvedItemsCount = $resolvedItemsCountByOrderId[(int) $order->order_id] ?? (int) $order->items_count;

                return [
                    'order_id' => (int) $order->order_id,
                    'buyer_userid' => (int) $order->buyer_userid,
                    'created_unix' => (int) $order->start_time,
                    'short_datetime' => (string) $order->short_datetime,
                    'item_count' => $resolvedItemsCount,
                    'amount' => (float) $order->order_total,
                    'status' => (string) $order->order_state,
                    'payment_status' => (string) $order->payment_status,
                ];
            })->values();

            return response()->json($this->sanitizeForJson([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $data->count(), $total),
                    'has_more' => $page < $lastPage,
                ],
            ]));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sales Invoice list for one customer — same "invoiced" rule used across the
     * ledger/outstanding reports: an orders row counts as an invoice once
     * COALESCE(order_total, 0) > 0, dated by Bill_Dt.
     * GET /api/orders/customer/{buyerUserId}/invoices?from=&to=&page=&per_page=
     */
    public function customerInvoices(Request $request, $buyerUserId)
    {
        if (!ctype_digit((string) $buyerUserId) || (int) $buyerUserId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'buyer_userid must be a positive integer',
            ], 422);
        }

        try {
            $resolvedBuyerUserId = (int) $buyerUserId;
            $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
            $page = max((int) $request->get('page', 1), 1);

            $query = DB::table('orders')
                ->where('buyer_userid', $resolvedBuyerUserId)
                ->whereRaw('COALESCE(order_total, 0) > 0');

            if ($from = $request->get('from')) {
                $query->whereDate('Bill_Dt', '>=', $from);
            }
            if ($to = $request->get('to')) {
                $query->whereDate('Bill_Dt', '<=', $to);
            }

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $rows = $query
                ->orderByDesc('Bill_Dt')
                ->orderByDesc('order_id')
                ->offset($offset)
                ->limit($perPage)
                ->select('order_id', 'bill_no', 'Bill_Dt', 'order_total', 'Bill_Narration')
                ->get();

            $data = $rows->map(fn ($o) => [
                'order_id' => (int) $o->order_id,
                'doc_no' => $o->bill_no ?: (string) $o->order_id,
                'doc_date' => $o->Bill_Dt,
                'amount' => (float) $o->order_total,
                'narration' => $o->Bill_Narration,
            ])->values();

            return response()->json($this->sanitizeForJson([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $data->count(), $total),
                    'has_more' => $page < $lastPage,
                ],
            ]));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sales Return list for one customer — matches LedgerService's rule: an
     * orders row counts as a return once Sales_Return_VoucherNo is populated,
     * dated by Sales_Return_Dt. Amount is recomputed from orders_item since
     * there's no return-amount column on `orders` itself.
     * GET /api/orders/customer/{buyerUserId}/returns?from=&to=&page=&per_page=
     */
    public function customerReturns(Request $request, $buyerUserId)
    {
        if (!ctype_digit((string) $buyerUserId) || (int) $buyerUserId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'buyer_userid must be a positive integer',
            ], 422);
        }

        try {
            if (!\Schema::hasTable('orders_item') || !\Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'per_page' => 20,
                        'current_page' => 1,
                        'last_page' => 1,
                        'from' => 0,
                        'to' => 0,
                        'has_more' => false,
                    ],
                ]);
            }

            $resolvedBuyerUserId = (int) $buyerUserId;
            $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
            $page = max((int) $request->get('page', 1), 1);

            $query = DB::table('orders')
                ->where('buyer_userid', $resolvedBuyerUserId)
                ->whereNotNull('Sales_Return_VoucherNo')
                ->where('Sales_Return_VoucherNo', '!=', '');

            if ($from = $request->get('from')) {
                $query->whereDate('Sales_Return_Dt', '>=', $from);
            }
            if ($to = $request->get('to')) {
                $query->whereDate('Sales_Return_Dt', '<=', $to);
            }

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $rows = $query
                ->orderByDesc('Sales_Return_Dt')
                ->orderByDesc('order_id')
                ->offset($offset)
                ->limit($perPage)
                ->select('order_id', 'Sales_Return_VoucherNo', 'Sales_Return_Dt', 'Sales_Return_Reason')
                ->get();

            $amountByOrderId = DB::table('orders_item')
                ->whereIn('order_id', $rows->pluck('order_id')->all())
                ->groupBy('order_id')
                ->select('order_id', DB::raw('COALESCE(SUM(qty_returned * item_price), 0) as t'))
                ->pluck('t', 'order_id');

            $data = $rows->map(fn ($o) => [
                'order_id' => (int) $o->order_id,
                'doc_no' => (string) $o->Sales_Return_VoucherNo,
                'doc_date' => $o->Sales_Return_Dt,
                'amount' => (float) ($amountByOrderId[$o->order_id] ?? 0),
                'reason' => $o->Sales_Return_Reason,
            ])->values();

            return response()->json($this->sanitizeForJson([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $data->count(), $total),
                    'has_more' => $page < $lastPage,
                ],
            ]));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function customerProductHistory(Request $request, $buyerUserId)
    {
        if (!ctype_digit((string) $buyerUserId) || (int) $buyerUserId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'buyer_userid must be a positive integer',
            ], 422);
        }

        try {
            if (!\Schema::hasTable('orders_item')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'per_page' => 20,
                        'current_page' => 1,
                        'last_page' => 1,
                        'from' => 0,
                        'to' => 0,
                        'has_more' => false,
                    ],
                ]);
            }

            $resolvedBuyerUserId = (int) $buyerUserId;
            $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
            $page = max((int) $request->get('page', 1), 1);

            $range = $this->resolveDateRangeFromRequest($request);
            if (isset($range['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $range['error'],
                ], 422);
            }

            $hasPinfo = \Schema::hasColumn('orders_item', 'pinfo');

            $baseQuery = DB::table('orders as o')
                ->join('orders_item as oi', 'oi.order_id', '=', 'o.order_id')
                ->leftJoin('product as p', 'p.product_id', '=', 'oi.product_id')
                ->where('o.buyer_userid', $resolvedBuyerUserId);

            if ($range['fromTs'] !== null && $range['toTs'] !== null) {
                $baseQuery->whereBetween('o.start_time', [$range['fromTs'], $range['toTs']]);
            } elseif ($range['fromTs'] !== null) {
                $baseQuery->where('o.start_time', '>=', $range['fromTs']);
            } elseif ($range['toTs'] !== null) {
                $baseQuery->where('o.start_time', '<=', $range['toTs']);
            }

            $grouped = clone $baseQuery;
            $grouped
                ->select(
                    'oi.product_id',
                    'p.name as product_name',
                    DB::raw('MAX(p.display_photo) as product_image'),
                    $hasPinfo ? 'oi.pinfo' : DB::raw("'' as pinfo"),
                    DB::raw('SUM(COALESCE(oi.quantity, 0)) as total_quantity'),
                    DB::raw('COUNT(DISTINCT o.order_id) as orders_count'),
                    DB::raw('SUM(COALESCE(oi.item_total, 0)) as total_amount')
                )
                ->groupBy('oi.product_id', 'p.name');

            if ($hasPinfo) {
                $grouped->groupBy('oi.pinfo');
            }

            $countQuery = clone $grouped;
            $total = DB::query()->fromSub($countQuery, 'product_history')->count();
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            $rows = $grouped
                ->orderBy('product_name')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $data = $rows->map(function ($row) {
                $pinfo = [];
                $rawPinfo = (string) ($row->pinfo ?? '');
                if ($rawPinfo !== '') {
                    $decoded = json_decode($this->sanitizeJsonString($rawPinfo), true);
                    if (is_array($decoded)) {
                        $pinfo = $decoded;
                    }
                }

                $packageLabel = trim((string) ($pinfo['ps'] ?? $pinfo['tx'] ?? $pinfo['name'] ?? ''));
                $packageUnit = trim((string) ($pinfo['pu'] ?? $pinfo['unit'] ?? ''));

                return [
                    'product_id' => (int) ($row->product_id ?? 0),
                    'product_name' => trim((string) ($row->product_name ?? '')),
                    'product_image' => trim((string) ($row->product_image ?? '')),
                    'package_label' => $packageLabel,
                    'package_unit' => $packageUnit,
                    'package_details' => trim(($packageLabel . ' ' . $packageUnit)),
                    'quantity_purchased' => (float) ($row->total_quantity ?? 0),
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'total_amount_spent' => (float) ($row->total_amount ?? 0),
                ];
            })->values();

            return response()->json($this->sanitizeForJson([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $data->count(), $total),
                    'has_more' => $page < $lastPage,
                ],
            ]));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function decodeDeliveryInfo($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($this->sanitizeJsonString($value), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function sanitizeForJson($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeForJson($item);
            }
            return $value;
        }

        if (is_string($value)) {
            return $this->sanitizeJsonString($value);
        }

        return $value;
    }

    private function sanitizeJsonString(string $value): string
    {
        $normalized = $value;
        $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $normalized);
        if (is_string($iconv)) {
            $normalized = $iconv;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $normalized);
        return is_string($clean) ? $clean : $normalized;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function resolveDateRangeFromRequest(Request $request): array
    {
        $fromDate = trim((string) $request->get('from_date', ''));
        $toDate = trim((string) $request->get('to_date', ''));
        $fromTsParam = trim((string) $request->get('from_ts', ''));
        $toTsParam = trim((string) $request->get('to_ts', ''));

        $fromTs = null;
        $toTs = null;

        if ($fromTsParam !== '') {
            if (!ctype_digit($fromTsParam)) {
                return ['error' => 'from_ts must be a positive integer'];
            }
            $fromTs = (int) $fromTsParam;
        }

        if ($toTsParam !== '') {
            if (!ctype_digit($toTsParam)) {
                return ['error' => 'to_ts must be a positive integer'];
            }
            $toTs = (int) $toTsParam;
        }

        if ($fromDate !== '') {
            $parsedFromTs = $this->parseDateToUnix($fromDate, true);
            if ($parsedFromTs === null) {
                return ['error' => 'Invalid from_date format. Use Y-m-d, d/m/Y, or d-m-Y'];
            }
            $fromTs = $parsedFromTs;
        }

        if ($toDate !== '') {
            $parsedToTs = $this->parseDateToUnix($toDate, false);
            if ($parsedToTs === null) {
                return ['error' => 'Invalid to_date format. Use Y-m-d, d/m/Y, or d-m-Y'];
            }
            $toTs = $parsedToTs;
        }

        if ($fromTs !== null && $toTs !== null && $fromTs > $toTs) {
            return ['error' => 'from_date cannot be greater than to_date'];
        }

        return [
            'fromTs' => $fromTs,
            'toTs' => $toTs,
        ];
    }

    private function parseDateToUnix(string $dateText, bool $startOfDay): ?int
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $dateText);
                return $startOfDay
                    ? $parsed->startOfDay()->timestamp
                    : $parsed->endOfDay()->timestamp;
            } catch (\Exception $e) {
                // Try next format.
            }
        }

        return null;
    }

    private function resolveItemsCountForOrders($orders): array
    {
        try {
            if ($orders->isEmpty() || !\Schema::hasTable('orders_item')) {
                return [];
            }

            $orderIds = $orders
                ->pluck('order_id')
                ->map(fn($v) => (int) $v)
                ->filter(fn($v) => $v > 0)
                ->unique()
                ->values()
                ->all();

            $masterOrderIds = $orders
                ->pluck('master_order_id')
                ->map(fn($v) => (int) $v)
                ->filter(fn($v) => $v > 0)
                ->unique()
                ->values()
                ->all();

            $candidateIds = array_values(array_unique(array_merge($orderIds, $masterOrderIds)));

            if (empty($candidateIds)) {
                return [];
            }

            $countByOrderId = DB::table('orders_item')
                ->whereIn('order_id', $candidateIds)
                ->groupBy('order_id')
                ->select('order_id', DB::raw('COUNT(*) as cnt'))
                ->pluck('cnt', 'order_id')
                ->map(fn($v) => (int) $v)
                ->toArray();

            $countByOpId = [];
            if (\Schema::hasColumn('orders_item', 'op_id')) {
                $countByOpId = DB::table('orders_item')
                    ->whereIn('op_id', $candidateIds)
                    ->groupBy('op_id')
                    ->select('op_id', DB::raw('COUNT(*) as cnt'))
                    ->pluck('cnt', 'op_id')
                    ->map(fn($v) => (int) $v)
                    ->toArray();
            }

            $resolved = [];
            foreach ($orders as $order) {
                $orderId = (int) ($order->order_id ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $masterId = (int) ($order->master_order_id ?? 0);
                $fallback = (int) ($order->items_count ?? 0);

                $resolved[$orderId] = max(
                    $fallback,
                    $countByOrderId[$orderId] ?? 0,
                    $masterId > 0 ? ($countByOrderId[$masterId] ?? 0) : 0,
                    $countByOpId[$orderId] ?? 0,
                    $masterId > 0 ? ($countByOpId[$masterId] ?? 0) : 0,
                );
            }

            return $resolved;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getOrderItems($order): array
    {
        try {
            if (!\Schema::hasTable('orders_item')) {
                return [];
            }

            // Only fetch items for the specific order_id (not master_order_id or related orders)
            $orderId = (int) ($order->order_id ?? 0);
            if ($orderId <= 0) {
                return [];
            }

            $hasPinfo = \Schema::hasColumn('orders_item', 'pinfo');

            $items = DB::table('orders_item as oi')
                ->leftJoin('product as p', 'p.product_id', '=', 'oi.product_id')
                ->where('oi.order_id', $orderId)
                ->select(
                    'oi.product_id',
                    'p.name as product_name',
                    'p.display_photo as product_image',
                    'oi.quantity',
                    'oi.qty_delivered',
                    'oi.qty_returned',
                    'oi.item_price',
                    'oi.item_total',
                    'oi.commission',
                    $hasPinfo ? 'oi.pinfo' : DB::raw("'' as pinfo")
                )
                ->get();

            if ($items->isEmpty() && (int) ($order->items_count ?? 0) > 0) {
                Log::warning('Order items empty but items_count > 0', [
                    'order_id' => $order->order_id ?? null,
                    'items_count' => (int) ($order->items_count ?? 0),
                ]);
            }

            return $items->map(function ($item) {
                $pinfo = [];
                if (isset($item->pinfo) && is_string($item->pinfo) && trim($item->pinfo) !== '') {
                    $decoded = json_decode($this->sanitizeJsonString($item->pinfo), true);
                    if (is_array($decoded)) {
                        $pinfo = $decoded;
                    }
                }

                $pinfoName = trim((string) ($pinfo['ps'] ?? $pinfo['tx'] ?? $pinfo['name'] ?? ''));
                $unit = trim((string) ($pinfo['pu'] ?? $pinfo['unit'] ?? ''));
                $productId = (int) ($item->product_id ?? 0);
                $joinedName = trim((string) ($item->product_name ?? ''));

                return [
                    'product_id' => $productId,
                    'product_name' => $joinedName !== ''
                        ? $joinedName
                        : ($pinfoName !== '' ? $pinfoName : 'Product #' . $productId),
                    'product_image' => $item->product_image ? (string) $item->product_image : '',
                    'quantity' => (int) ($item->quantity ?? 0),
                    'qty_delivered' => (int) ($item->qty_delivered ?? 0),
                    'qty_returned' => (int) ($item->qty_returned ?? 0),
                    'item_price' => (float) ($item->item_price ?? 0),
                    'item_total' => (float) ($item->item_total ?? 0),
                    'commission' => (float) ($item->commission ?? 0),
                    'unit' => $unit,
                ];
            })->values()->all();
        } catch (\Exception $e) {
            Log::error('Failed to fetch order items', [
                'order_id' => $order->order_id ?? null,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function getAdminNamesByIds(array $ids): array
    {
        try {
            $cleanIds = array_values(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0));
            if (empty($cleanIds) || !\Schema::hasTable('admin')) {
                return [];
            }

            return DB::table('admin')
                ->whereIn('userid', $cleanIds)
                ->pluck('name', 'userid')
                ->map(fn($name) => is_string($name) ? trim($name) : '')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getShopNamesByBuyerIds(array $ids): array
    {
        try {
            $cleanIds = array_values(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0));
            if (empty($cleanIds) || !\Schema::hasTable('user') || !\Schema::hasColumn('user', 'shop_name')) {
                return [];
            }

            return DB::table('user')
                ->whereIn('userid', $cleanIds)
                ->pluck('shop_name', 'userid')
                ->map(fn($name) => is_string($name) ? trim($name) : '')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function resolveDriverDetails($order, array $deliveryInfo): array
    {
        $name = '';
        $contact = '';

        $deliId = isset($order->deli_id) ? (int) $order->deli_id : 0;
        if ($deliId > 0 && \Schema::hasTable('deli_staff')) {
            $driver = DB::table('deli_staff')
                ->where('deli_id', $deliId)
                ->where('role', 'driver')
                ->select('name', 'mobile')
                ->first();

            if ($driver) {
                $name = trim((string) ($driver->name ?? ''));
                $contact = trim((string) ($driver->mobile ?? ''));
            }
        }

        if ($name === '') {
            $name = trim((string) ($deliveryInfo['driverName'] ?? $deliveryInfo['driver_name'] ?? ''));
        }

        if ($contact === '') {
            $contact = trim((string) ($deliveryInfo['driverNumber'] ?? $deliveryInfo['driverContact'] ?? $deliveryInfo['driver_contact'] ?? ''));
        }

        return [
            'name' => $name,
            'contact' => $contact,
        ];
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes'], true);
    }
}
