<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only listings over the purchase-side documents (purchase_orders /
 * purchase_vouchers / purchase_returns). These tables have no Eloquent
 * models — every other part of the app (LedgerService, OutstandingService,
 * VoucherPostingService) reads them via DB::table() too, so this controller
 * keeps that same convention rather than introducing new models.
 */
class PurchaseController extends Controller
{
    /**
     * Purchase Order list for one supplier. There's no existing "is this a
     * real PO" business rule elsewhere in the app, so this simply lists every
     * row regardless of status — same as how Sales Order shows every order
     * regardless of fulfillment status.
     * GET /api/purchases/orders?supplier_id=&from=&to=&search=&page=&per_page=
     */
    public function orders(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id', 0);
        if ($supplierId <= 0) {
            return response()->json(['status' => false, 'message' => 'supplier_id is required'], 422);
        }

        $query = DB::table('purchase_orders')->where('supplier_id', $supplierId);
        $this->applyDateRange($query, $request, 'doc_date');
        if ($search = trim((string) $request->get('search', ''))) {
            $query->where('po_number', 'like', "%{$search}%");
        }

        [$data, $pagination] = $this->paginate($query, $request, 'doc_date', function ($row) {
            return [
                'id' => (int) $row->id,
                'doc_no' => $row->po_number,
                'doc_date' => $row->doc_date,
                'status' => $row->status,
                'amount' => (float) $row->total_with_charges,
                'expected_date' => $row->expected_date,
                'narration' => $row->narration,
            ];
        });

        return response()->json(['status' => true, 'data' => $data, 'pagination' => $pagination]);
    }

    /**
     * Purchase Invoice list for one supplier — the same rule already relied
     * on by supplier ledger/outstanding: status POSTED and
     * COALESCE(net_total, 0) > 0, dated by doc_date.
     * GET /api/purchases/invoices?supplier_id=&from=&to=&page=&per_page=
     */
    public function invoices(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id', 0);
        if ($supplierId <= 0) {
            return response()->json(['status' => false, 'message' => 'supplier_id is required'], 422);
        }

        $query = DB::table('purchase_vouchers')
            ->where('supplier_id', $supplierId)
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0');
        $this->applyDateRange($query, $request, 'doc_date');

        [$data, $pagination] = $this->paginate($query, $request, 'doc_date', function ($row) {
            return [
                'id' => (int) $row->id,
                'doc_no' => $row->bill_no ?: ($row->doc_no ?: (string) $row->id),
                'doc_date' => $row->doc_date,
                'status' => $row->status,
                'amount' => (float) $row->net_total,
                'extra' => $row->bill_date,
            ];
        });

        return response()->json(['status' => true, 'data' => $data, 'pagination' => $pagination]);
    }

    /**
     * Purchase Return list for one supplier — the same rule already relied
     * on by supplier ledger/outstanding: status POSTED and
     * COALESCE(net_total, 0) > 0, dated by doc_date.
     * GET /api/purchases/returns?supplier_id=&from=&to=&page=&per_page=
     */
    public function returns(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id', 0);
        if ($supplierId <= 0) {
            return response()->json(['status' => false, 'message' => 'supplier_id is required'], 422);
        }

        $query = DB::table('purchase_returns')
            ->where('supplier_id', $supplierId)
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0');
        $this->applyDateRange($query, $request, 'doc_date');

        [$data, $pagination] = $this->paginate($query, $request, 'doc_date', function ($row) {
            return [
                'id' => (int) $row->id,
                'doc_no' => $row->doc_no,
                'doc_date' => $row->doc_date,
                'status' => $row->status,
                'amount' => (float) $row->net_total,
                'extra' => $row->reason,
            ];
        });

        return response()->json(['status' => true, 'data' => $data, 'pagination' => $pagination]);
    }

    private function applyDateRange($query, Request $request, string $column): void
    {
        if ($from = $request->get('from')) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate($column, '<=', $to);
        }
    }

    private function paginate($query, Request $request, string $orderColumn, \Closure $mapRow): array
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);
        $page = max((int) $request->get('page', 1), 1);

        $total = (clone $query)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $rows = $query
            ->orderByDesc($orderColumn)
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $data = $rows->map($mapRow)->values();

        return [$data, [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ]];
    }
}
