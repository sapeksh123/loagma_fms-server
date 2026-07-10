<?php

namespace App\Http\Controllers;

use App\Models\GeneralAccount;
use App\Services\LedgerService;
use App\Services\OutstandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function __construct(
        private OutstandingService $outstanding,
        private LedgerService $ledger,
    ) {
    }

    /**
     * General Ledger for one general_account, with running balance (Dr - Cr).
     * GET /api/reports/general-ledger?account_id=&from=&to=
     */
    public function generalLedger(Request $request)
    {
        $accountId = (int) $request->get('account_id', 0);
        if ($accountId <= 0) {
            return response()->json(['status' => false, 'message' => 'account_id is required'], 422);
        }

        $account = GeneralAccount::find($accountId);
        if (! $account) {
            return response()->json(['status' => false, 'message' => 'Account not found'], 404);
        }

        $q = DB::table('ledger_entries as le')
            ->join('vouchers as v', 'v.id', '=', 'le.voucher_id')
            ->where('le.ledger_source', 'GENERAL')
            ->where('le.ledger_id', $accountId)
            ->where('v.status', 'POSTED');
        $this->applyDateRange($q, $request, 'le.entry_date');

        $rows = $q->orderBy('le.entry_date')->orderBy('le.id')
            ->select('le.entry_date', 'le.dr_amount', 'le.cr_amount', 'v.voucher_no', 'v.voucher_type', 'v.narration')
            ->get();

        $balance = 0.0;
        $data = $rows->map(function ($r) use (&$balance) {
            $balance += (float) $r->dr_amount - (float) $r->cr_amount;
            return [
                'entry_date' => $r->entry_date,
                'voucher_no' => $r->voucher_no,
                'voucher_type' => $r->voucher_type,
                'narration' => $r->narration,
                'dr_amount' => (float) $r->dr_amount,
                'cr_amount' => (float) $r->cr_amount,
                'balance' => round($balance, 2),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'account' => ['id' => $account->id, 'name' => $account->account_name, 'type' => $account->account_type],
            'data' => $data,
            'closing_balance' => round($balance, 2),
        ]);
    }

    /**
     * Customer Ledger: sales invoices (Dr) + receipts (Cr), running balance (Dr - Cr).
     * GET /api/reports/customer-ledger?user_id=&from=&to=
     */
    public function customerLedger(Request $request)
    {
        $userId = (int) $request->get('user_id', 0);
        if ($userId <= 0) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        $name = DB::table('user')->where('userid', $userId)->value('name');

        // Invoices (debit the customer).
        $inv = DB::table('orders')
            ->where('buyer_userid', $userId)
            ->whereRaw('COALESCE(order_total, 0) > 0');
        $this->applyDateRange($inv, $request, 'Bill_Dt');
        $invoices = $inv->select('order_id', 'bill_no', 'Bill_Dt', 'order_total')->get()
            ->map(fn ($o) => [
                'date' => $o->Bill_Dt,
                'doc_ref' => $o->bill_no ?: (string) $o->order_id,
                'particulars' => 'Sales Invoice',
                'dr_amount' => (float) $o->order_total,
                'cr_amount' => 0.0,
            ]);

        // Receipts (credit the customer) from CR/BR vouchers.
        $rec = DB::table('ledger_entries as le')
            ->join('vouchers as v', 'v.id', '=', 'le.voucher_id')
            ->where('le.ledger_source', 'CUSTOMER')
            ->where('le.ledger_id', $userId)
            ->where('v.status', 'POSTED');
        $this->applyDateRange($rec, $request, 'le.entry_date');
        $receipts = $rec->select('le.entry_date', 'le.dr_amount', 'le.cr_amount', 'v.voucher_no', 'v.voucher_type')->get()
            ->map(fn ($r) => [
                'date' => $r->entry_date,
                'doc_ref' => $r->voucher_no,
                'particulars' => $r->voucher_type === 'CR' ? 'Cash Receipt' : 'Bank Receipt',
                'dr_amount' => (float) $r->dr_amount,
                'cr_amount' => (float) $r->cr_amount,
            ]);

        // Sales returns (credit the customer) recorded on the orders row.
        $returns = collect();
        if (Schema::hasTable('orders_item') && Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
            $retQ = DB::table('orders')
                ->where('buyer_userid', $userId)
                ->whereNotNull('Sales_Return_VoucherNo')
                ->where('Sales_Return_VoucherNo', '!=', '');
            $this->applyDateRange($retQ, $request, 'Sales_Return_Dt');
            $retOrders = $retQ->select('order_id', 'Sales_Return_VoucherNo', 'Sales_Return_Dt')->get();
            if ($retOrders->isNotEmpty()) {
                $retTotals = DB::table('orders_item')
                    ->whereIn('order_id', $retOrders->pluck('order_id')->all())
                    ->groupBy('order_id')
                    ->select('order_id', DB::raw('COALESCE(SUM(qty_returned * item_price), 0) as t'))
                    ->pluck('t', 'order_id');
                $returns = $retOrders->map(fn ($o) => [
                    'date' => $o->Sales_Return_Dt,
                    'doc_ref' => $o->Sales_Return_VoucherNo,
                    'particulars' => 'Sales Return',
                    'dr_amount' => 0.0,
                    'cr_amount' => round((float) ($retTotals[$o->order_id] ?? 0), 2),
                ])->filter(fn ($x) => $x['cr_amount'] > 0.001)->values();
            }
        }

        $data = $this->mergeRunning($invoices->concat($receipts)->concat($returns), receivable: true);

        return response()->json([
            'status' => true,
            'party' => ['type' => 'CUSTOMER', 'id' => $userId, 'name' => $name],
            'data' => $data['rows'],
            'closing_balance' => $data['closing'],
        ]);
    }

    /**
     * Supplier Ledger: purchase invoices (Cr) + payments (Dr), running balance (Cr - Dr).
     * GET /api/reports/supplier-ledger?supplier_id=&from=&to=
     */
    public function supplierLedger(Request $request)
    {
        $supplierId = (int) $request->get('supplier_id', 0);
        if ($supplierId <= 0) {
            return response()->json(['status' => false, 'message' => 'supplier_id is required'], 422);
        }

        $name = DB::table('suppliers')->where('id', $supplierId)->value('supplier_name');
        $invoices = collect();

        if (Schema::hasTable('purchase_vouchers')) {
            $inv = DB::table('purchase_vouchers')
                ->where('supplier_id', $supplierId)
                ->where('status', 'POSTED')
                ->whereRaw('COALESCE(net_total, 0) > 0');
            $this->applyDateRange($inv, $request, 'doc_date');
            $invoices = $inv->select('id', 'doc_no', 'bill_no', 'doc_date', 'net_total')->get()
                ->map(fn ($p) => [
                    'date' => $p->doc_date,
                    'doc_ref' => $p->bill_no ?: ($p->doc_no ?: (string) $p->id),
                    'particulars' => 'Purchase Invoice',
                    'dr_amount' => 0.0,
                    'cr_amount' => (float) $p->net_total,
                ]);
        }

        $pay = DB::table('ledger_entries as le')
            ->join('vouchers as v', 'v.id', '=', 'le.voucher_id')
            ->where('le.ledger_source', 'SUPPLIER')
            ->where('le.ledger_id', $supplierId)
            ->where('v.status', 'POSTED');
        $this->applyDateRange($pay, $request, 'le.entry_date');
        $payments = $pay->select('le.entry_date', 'le.dr_amount', 'le.cr_amount', 'v.voucher_no', 'v.voucher_type')->get()
            ->map(fn ($r) => [
                'date' => $r->entry_date,
                'doc_ref' => $r->voucher_no,
                'particulars' => $r->voucher_type === 'CP' ? 'Cash Payment' : 'Bank Payment',
                'dr_amount' => (float) $r->dr_amount,
                'cr_amount' => (float) $r->cr_amount,
            ]);

        // Purchase returns (debit the supplier) from purchase_returns.
        $returns = collect();
        if (Schema::hasTable('purchase_returns')) {
            $ret = DB::table('purchase_returns')
                ->where('supplier_id', $supplierId)
                ->where('status', 'POSTED')
                ->whereRaw('COALESCE(net_total, 0) > 0');
            $this->applyDateRange($ret, $request, 'doc_date');
            $returns = $ret->select('doc_no', 'doc_date', 'net_total')->get()
                ->map(fn ($r) => [
                    'date' => $r->doc_date,
                    'doc_ref' => $r->doc_no,
                    'particulars' => 'Purchase Return',
                    'dr_amount' => (float) $r->net_total,
                    'cr_amount' => 0.0,
                ]);
        }

        $data = $this->mergeRunning($invoices->concat($payments)->concat($returns), receivable: false);

        return response()->json([
            'status' => true,
            'party' => ['type' => 'SUPPLIER', 'id' => $supplierId, 'name' => $name],
            'data' => $data['rows'],
            'closing_balance' => $data['closing'],
        ]);
    }

    /**
     * Outstanding summary per party.
     * GET /api/reports/outstanding?party_type=CUSTOMER|SUPPLIER
     */
    public function outstanding(Request $request)
    {
        $partyType = strtoupper(trim((string) $request->get('party_type', 'CUSTOMER')));

        $data = match ($partyType) {
            'CUSTOMER' => $this->outstanding->customerOutstandingSummary(),
            'SUPPLIER' => $this->outstanding->supplierOutstandingSummary(),
            default => null,
        };

        if ($data === null) {
            return response()->json(['status' => false, 'message' => 'party_type must be CUSTOMER or SUPPLIER'], 422);
        }

        return response()->json([
            'status' => true,
            'party_type' => $partyType,
            'data' => $data,
            'total_outstanding' => round(array_sum(array_column($data, 'balance')), 2),
        ]);
    }

    /**
     * Tally-style outstanding detail grouped by party, pending only, as on a date.
     * GET /api/reports/outstanding-detail?party_type=CUSTOMER|SUPPLIER&as_on=YYYY-MM-DD&account_ids=1,2
     */
    public function outstandingDetail(Request $request)
    {
        $partyType = strtoupper(trim((string) $request->get('party_type', 'CUSTOMER')));
        if (! in_array($partyType, ['CUSTOMER', 'SUPPLIER'], true)) {
            return response()->json(['status' => false, 'message' => 'party_type must be CUSTOMER or SUPPLIER'], 422);
        }

        $asOnRaw = (string) $request->get('as_on', '');
        $asOn = $asOnRaw !== '' && strtotime($asOnRaw) !== false
            ? date('Y-m-d', strtotime($asOnRaw))
            : date('Y-m-d');

        $accountIds = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->get('account_ids', ''))),
            fn ($id) => $id > 0
        ));

        return response()->json([
            'status' => true,
            'party_type' => $partyType,
            'data' => $this->outstanding->outstandingDetail($partyType, $asOn, $accountIds),
        ]);
    }

    /**
     * Tally-style grouped ledger over a date range, with opening balance per account.
     * GET /api/reports/ledger-detail?type=CUSTOMER|SUPPLIER|GENERAL&from=&to=&account_ids=1,2
     */
    public function ledgerDetail(Request $request)
    {
        $type = strtoupper(trim((string) $request->get('type', 'CUSTOMER')));
        if (! in_array($type, ['CUSTOMER', 'SUPPLIER', 'GENERAL'], true)) {
            return response()->json(['status' => false, 'message' => 'type must be CUSTOMER, SUPPLIER or GENERAL'], 422);
        }

        $norm = fn (string $key): ?string => ($v = (string) $request->get($key, '')) !== '' && strtotime($v) !== false
            ? date('Y-m-d', strtotime($v))
            : null;

        $accountIds = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->get('account_ids', ''))),
            fn ($id) => $id > 0
        ));

        return response()->json([
            'status' => true,
            'type' => $type,
            'data' => $this->ledger->ledgerDetail($type, $norm('from'), $norm('to'), $accountIds),
        ]);
    }

    /**
     * Post-dated cheques (PDC issued / PDR received), grouped by party like the
     * Tally-style Outstanding Report — one group per customer/supplier, one row
     * per cheque, with sub-totals. Ordered by cheque due date within each party;
     * the app flags PENDING cheques whose cheque_date has passed as overdue.
     * GET /api/reports/pdc-outstanding?party_type=CUSTOMER|SUPPLIER&account_ids=1,2
     *                                  &voucher_type=PDC|PDR&status=PENDING|CLEARED|BOUNCED|ALL
     */
    public function pdcOutstanding(Request $request)
    {
        $partyType = strtoupper(trim((string) $request->get('party_type', 'CUSTOMER')));
        if (! in_array($partyType, ['CUSTOMER', 'SUPPLIER'], true)) {
            return response()->json(['status' => false, 'message' => 'party_type must be CUSTOMER or SUPPLIER'], 422);
        }

        $voucherType = strtoupper(trim((string) $request->get('voucher_type', '')));
        $status = strtoupper(trim((string) $request->get('status', 'PENDING')));
        $accountIds = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->get('account_ids', ''))),
            fn ($id) => $id > 0
        ));

        $q = DB::table('voucher_pdc_details as pd')
            ->join('vouchers as v', 'v.id', '=', 'pd.voucher_id')
            ->join('voucher_details as vd', 'vd.voucher_id', '=', 'v.id')
            ->where('v.status', 'POSTED')
            ->where('vd.account_category', $partyType);

        if (in_array($voucherType, ['PDC', 'PDR'], true)) {
            $q->where('v.voucher_type', $voucherType);
        } else {
            $q->whereIn('v.voucher_type', ['PDC', 'PDR']);
        }

        if ($status !== '' && $status !== 'ALL') {
            $q->where('pd.status', $status);
        }

        if (! empty($accountIds)) {
            $q->whereIn('vd.account_id', $accountIds);
        }

        $rows = $q->orderBy('pd.cheque_date')
            ->select(
                'v.id', 'v.voucher_no', 'v.voucher_type', 'v.voucher_date', 'v.narration',
                'vd.account_id as party_id', 'vd.amount as row_amount',
                'pd.cheque_no', 'pd.cheque_date', 'pd.bank_name', 'pd.status as pdc_status',
                'pd.cleared_date', 'pd.bounced_date'
            )
            ->get()
            // A cheque is single-party (one detail row); guard against a stray
            // duplicate join row rather than double-counting it in the totals.
            ->unique('id')
            ->values();

        $partyIds = $rows->pluck('party_id')->unique()->values()->all();
        $partyInfo = $partyType === 'CUSTOMER'
            ? $this->customerPartyInfo($partyIds)
            : $this->supplierPartyInfo($partyIds);

        $today = date('Y-m-d');
        $byParty = $rows->groupBy('party_id')->map(function ($group, $partyId) use ($partyInfo, $today) {
            $info = $partyInfo[$partyId] ?? null;
            $cheques = $group->map(fn ($r) => [
                'voucher_id' => $r->id,
                'voucher_no' => $r->voucher_no,
                'voucher_type' => $r->voucher_type,
                'voucher_date' => $r->voucher_date,
                'narration' => $r->narration,
                'amount' => (float) $r->row_amount,
                'cheque_no' => $r->cheque_no,
                'cheque_date' => $r->cheque_date,
                'bank_name' => $r->bank_name,
                'pdc_status' => $r->pdc_status,
                'cleared_date' => $r->cleared_date,
                'bounced_date' => $r->bounced_date,
                'overdue' => $r->pdc_status === 'PENDING' && (string) $r->cheque_date < $today,
            ])->values();

            return [
                'party_id' => $partyId,
                'party_name' => $info['name'] ?? null,
                'address' => $info['address'] ?? null,
                'phone' => $info['phone'] ?? null,
                'contact_person' => $info['contact_person'] ?? null,
                'cheques' => $cheques,
                'total_amount' => round((float) $cheques->sum('amount'), 2),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'party_type' => $partyType,
            'data' => [
                'groups' => $byParty,
                'report_total_amount' => round((float) $byParty->sum('total_amount'), 2),
                'party_count' => $byParty->count(),
                'cheque_count' => $rows->count(),
            ],
        ]);
    }

    /** @return array<int,array{name:?string,address:?string,phone:?string,contact_person:?string}> */
    private function customerPartyInfo(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return DB::table('user')
            ->whereIn('userid', $userIds)
            ->get(['userid', 'name', 'shop_name', 'shop_address', 'address', 'contactno'])
            ->keyBy('userid')
            ->map(fn ($u) => [
                'name' => $u->shop_name ?: ($u->name ?: null),
                'address' => $u->shop_address ?: $u->address,
                'phone' => $u->contactno,
                'contact_person' => $u->name,
            ])
            ->all();
    }

    /** @return array<int,array{name:?string,address:?string,phone:?string,contact_person:?string}> */
    private function supplierPartyInfo(array $supplierIds): array
    {
        if (empty($supplierIds)) {
            return [];
        }

        return DB::table('suppliers')
            ->whereIn('id', $supplierIds)
            ->get(['id', 'supplier_name', 'contact_person', 'address_line1', 'city', 'phone'])
            ->keyBy('id')
            ->map(fn ($s) => [
                'name' => $s->supplier_name,
                'address' => trim(implode(', ', array_filter([$s->address_line1, $s->city]))) ?: null,
                'phone' => $s->phone,
                'contact_person' => $s->contact_person,
            ])
            ->all();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function applyDateRange($query, Request $request, string $column): void
    {
        if ($from = $request->get('from')) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * Sort merged ledger rows by date and compute a running balance.
     * receivable => balance = cum(Dr - Cr); payable => cum(Cr - Dr).
     */
    private function mergeRunning($rows, bool $receivable): array
    {
        $sorted = $rows->sortBy('date')->values();
        $balance = 0.0;

        $out = $sorted->map(function ($r) use (&$balance, $receivable) {
            $delta = $receivable
                ? $r['dr_amount'] - $r['cr_amount']
                : $r['cr_amount'] - $r['dr_amount'];
            $balance += $delta;
            $r['balance'] = round($balance, 2);
            return $r;
        })->values()->all();

        return ['rows' => $out, 'closing' => round($balance, 2)];
    }
}
