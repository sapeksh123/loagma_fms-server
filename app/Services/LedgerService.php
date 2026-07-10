<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tally-style grouped ledger for many accounts over a date range.
 *
 * Running balance convention:
 *   CUSTOMER / GENERAL -> Dr - Cr     (receivable / asset-debit)
 *   SUPPLIER           -> Cr - Dr     (payable)
 *
 * Each group carries an Opening Balance (net of everything before `from`) so the
 * running balance is the true carried-forward figure.
 */
class LedgerService
{
    public function ledgerDetail(string $type, ?string $from, ?string $to, array $accountIds = []): array
    {
        [$rowsByAccount, $openingByAccount] = match ($type) {
            'CUSTOMER' => $this->customer($from, $to, $accountIds),
            'SUPPLIER' => $this->supplier($from, $to, $accountIds),
            default => $this->general($from, $to, $accountIds),
        };

        // Accounts to show: those with a period row OR a non-zero opening balance.
        // When specific accounts are requested, narrow to that set.
        $candidates = array_values(array_unique(array_merge(
            array_keys($rowsByAccount),
            array_keys(array_filter($openingByAccount, fn ($v) => abs($v) > 0.001)),
        )));
        if (! empty($accountIds)) {
            $candidates = array_values(array_intersect($candidates, $accountIds));
        }
        $ids = $candidates;
        $names = $this->names($type, $ids);

        $isPayable = $type === 'SUPPLIER';
        $groups = [];
        $rowCount = 0;

        foreach ($ids as $accId) {
            $opening = round($openingByAccount[$accId] ?? 0.0, 2);
            $periodRows = $rowsByAccount[$accId] ?? [];
            usort($periodRows, fn ($a, $b) => [$a['date'], $a['seq']] <=> [$b['date'], $b['seq']]);

            $balance = $opening;
            $outRows = [];

            if ($from !== null) {
                $outRows[] = [
                    'date' => $from,
                    'voucher_no' => '',
                    'particulars' => 'Opening Balance',
                    'dr_amount' => 0.0,
                    'cr_amount' => 0.0,
                    'is_opening' => true,
                ] + $this->balanceFields($opening, $isPayable);
            }

            $totalDr = 0.0;
            $totalCr = 0.0;
            foreach ($periodRows as $r) {
                $totalDr += $r['dr'];
                $totalCr += $r['cr'];
                $balance += $isPayable ? ($r['cr'] - $r['dr']) : ($r['dr'] - $r['cr']);
                $outRows[] = [
                    'date' => $r['date'],
                    'voucher_no' => $r['voucher_no'],
                    'particulars' => $r['particulars'],
                    'bill_no' => $r['bill_no'],
                    'dr_amount' => round($r['dr'], 2),
                    'cr_amount' => round($r['cr'], 2),
                    'is_opening' => false,
                ] + $this->balanceFields($balance, $isPayable);
                $rowCount++;
            }

            $groups[] = [
                'account_id' => $accId,
                'account_name' => $names[$accId]['name'] ?? ($type . ' #' . $accId),
                'account_sub' => $names[$accId]['sub'] ?? null,
                'opening_balance' => $opening,
                'rows' => $outRows,
                'total_debit' => round($totalDr, 2),
                'total_credit' => round($totalCr, 2),
                'closing_balance' => round(abs($balance), 2),
                'closing_dr_cr' => $this->drCr($balance, $isPayable),
            ];
        }

        usort($groups, fn ($a, $b) => strcasecmp((string) $a['account_name'], (string) $b['account_name']));

        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'groups' => $groups,
            'report_total_debit' => round(array_sum(array_column($groups, 'total_debit')), 2),
            'report_total_credit' => round(array_sum(array_column($groups, 'total_credit')), 2),
            'account_count' => count($groups),
            'row_count' => $rowCount,
        ];
    }

    // ── Per-type period rows + opening ──────────────────────────────────────────

    /** @return array{0:array<int,array>,1:array<int,float>} */
    private function customer(?string $from, ?string $to, array $ids): array
    {
        $rows = [];

        // Sales invoices (debit the customer).
        $inv = DB::table('orders')->whereRaw('COALESCE(order_total, 0) > 0');
        $this->between($inv, 'Bill_Dt', $from, $to);
        $this->scope($inv, 'buyer_userid', $ids);
        foreach ($inv->select('order_id', 'buyer_userid', 'bill_no', 'Bill_Dt', 'order_total')->get() as $o) {
            $rows[(int) $o->buyer_userid][] = [
                'account_id' => (int) $o->buyer_userid,
                'date' => $o->Bill_Dt,
                'seq' => 0,
                'voucher_no' => $o->bill_no ?: (string) $o->order_id,
                'particulars' => 'Sales Invoice',
                'bill_no' => $o->bill_no,
                'dr' => (float) $o->order_total,
                'cr' => 0.0,
            ];
        }

        // Sales returns (credit the customer) recorded on the orders row.
        $this->addCustomerReturnRows($rows, $from, $to, $ids);

        // Receipts / refunds (CR/BR/CP/BP) from ledger_entries.
        $this->addVoucherRows($rows, 'CUSTOMER', $from, $to, $ids);

        $opening = $this->customerOpening($from, $ids);

        return [$rows, $opening];
    }

    /** @return array{0:array<int,array>,1:array<int,float>} */
    private function supplier(?string $from, ?string $to, array $ids): array
    {
        $rows = [];

        if (Schema::hasTable('purchase_vouchers')) {
            $inv = DB::table('purchase_vouchers')
                ->where('status', 'POSTED')
                ->whereRaw('COALESCE(net_total, 0) > 0');
            $this->between($inv, 'doc_date', $from, $to);
            $this->scope($inv, 'supplier_id', $ids);
            foreach ($inv->select('id', 'supplier_id', 'doc_no', 'bill_no', 'doc_date', 'net_total')->get() as $p) {
                $rows[(int) $p->supplier_id][] = [
                    'account_id' => (int) $p->supplier_id,
                    'date' => $p->doc_date,
                    'seq' => 0,
                    'voucher_no' => $p->bill_no ?: ($p->doc_no ?: (string) $p->id),
                    'particulars' => 'Purchase Invoice',
                    'bill_no' => $p->bill_no,
                    'dr' => 0.0,
                    'cr' => (float) $p->net_total,
                ];
            }
        }

        // Purchase returns (debit the supplier) from purchase_returns.
        $this->addSupplierReturnRows($rows, $from, $to, $ids);

        $this->addVoucherRows($rows, 'SUPPLIER', $from, $to, $ids);

        $opening = $this->supplierOpening($from, $ids);

        return [$rows, $opening];
    }

    /** @return array{0:array<int,array>,1:array<int,float>} */
    private function general(?string $from, ?string $to, array $ids): array
    {
        $rows = [];
        $this->addVoucherRows($rows, 'GENERAL', $from, $to, $ids);
        $opening = $this->voucherOpening('GENERAL', $from, $ids, payable: false);

        return [$rows, $opening];
    }

    /** Append POSTED-voucher ledger_entries for a source into $rows (by account). */
    private function addVoucherRows(array &$rows, string $source, ?string $from, ?string $to, array $ids): void
    {
        $q = DB::table('ledger_entries as le')
            ->join('vouchers as v', 'v.id', '=', 'le.voucher_id')
            ->where('le.ledger_source', $source)
            ->where('v.status', 'POSTED');
        $this->between($q, 'le.entry_date', $from, $to);
        $this->scope($q, 'le.ledger_id', $ids);

        $records = $q->orderBy('le.entry_date')->orderBy('le.id')
            ->select('le.ledger_id', 'le.entry_date', 'le.dr_amount', 'le.cr_amount', 'v.voucher_no', 'v.voucher_type', 'v.narration')
            ->get();

        foreach ($records as $r) {
            $acc = (int) $r->ledger_id;
            $particulars = $source === 'GENERAL'
                ? (($r->narration ?? '') !== '' ? $r->narration : $this->voucherParticular($r->voucher_type))
                : $this->voucherParticular($r->voucher_type);
            $rows[$acc][] = [
                'account_id' => $acc,
                'date' => $r->entry_date,
                'seq' => 1,
                'voucher_no' => $r->voucher_no,
                'particulars' => $particulars,
                'bill_no' => null,
                'dr' => (float) $r->dr_amount,
                'cr' => (float) $r->cr_amount,
            ];
        }
    }

    /**
     * Sales-return rows (Cr the customer) within [from,to]. Gross return value =
     * SUM(orders_item.qty_returned * item_price); settlements arrive separately via
     * addVoucherRows. Keyed into $rows by buyer.
     */
    private function addCustomerReturnRows(array &$rows, ?string $from, ?string $to, array $ids): void
    {
        if (! Schema::hasTable('orders_item') || ! Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
            return;
        }

        $q = DB::table('orders')
            ->whereNotNull('Sales_Return_VoucherNo')
            ->where('Sales_Return_VoucherNo', '!=', '');
        $this->between($q, 'Sales_Return_Dt', $from, $to);
        $this->scope($q, 'buyer_userid', $ids);
        $orders = $q->select('order_id', 'buyer_userid', 'Sales_Return_VoucherNo', 'Sales_Return_Dt')->get();
        if ($orders->isEmpty()) {
            return;
        }

        $totals = DB::table('orders_item')
            ->whereIn('order_id', $orders->pluck('order_id')->all())
            ->groupBy('order_id')
            ->select('order_id', DB::raw('COALESCE(SUM(qty_returned * item_price), 0) as t'))
            ->pluck('t', 'order_id');

        foreach ($orders as $o) {
            $total = round((float) ($totals[$o->order_id] ?? 0), 2);
            if ($total <= 0.001) {
                continue;
            }
            $rows[(int) $o->buyer_userid][] = [
                'account_id' => (int) $o->buyer_userid,
                'date' => $o->Sales_Return_Dt,
                'seq' => 0,
                'voucher_no' => $o->Sales_Return_VoucherNo,
                'particulars' => 'Sales Return',
                'bill_no' => $o->Sales_Return_VoucherNo,
                'dr' => 0.0,
                'cr' => $total,
            ];
        }
    }

    /** Purchase-return rows (Dr the supplier) within [from,to] from purchase_returns. */
    private function addSupplierReturnRows(array &$rows, ?string $from, ?string $to, array $ids): void
    {
        if (! Schema::hasTable('purchase_returns')) {
            return;
        }

        $q = DB::table('purchase_returns')
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0');
        $this->between($q, 'doc_date', $from, $to);
        $this->scope($q, 'supplier_id', $ids);

        foreach ($q->select('id', 'supplier_id', 'doc_no', 'doc_date', 'net_total')->get() as $r) {
            $rows[(int) $r->supplier_id][] = [
                'account_id' => (int) $r->supplier_id,
                'date' => $r->doc_date,
                'seq' => 0,
                'voucher_no' => $r->doc_no,
                'particulars' => 'Purchase Return',
                'bill_no' => $r->doc_no,
                'dr' => (float) $r->net_total,
                'cr' => 0.0,
            ];
        }
    }

    // ── Opening balances (before `from`) ────────────────────────────────────────

    /** @return array<int,float> account_id => opening (Dr - Cr). */
    private function customerOpening(?string $from, array $ids): array
    {
        if ($from === null) {
            return [];
        }
        $open = [];

        $inv = DB::table('orders')->whereDate('Bill_Dt', '<', $from)->whereRaw('COALESCE(order_total, 0) > 0');
        $this->scope($inv, 'buyer_userid', $ids);
        foreach ($inv->groupBy('buyer_userid')->select('buyer_userid', DB::raw('SUM(order_total) as t'))->get() as $r) {
            $open[(int) $r->buyer_userid] = ($open[(int) $r->buyer_userid] ?? 0) + (float) $r->t;
        }

        // Sales returns before `from` credit the customer (reduce the receivable opening).
        if (Schema::hasTable('orders_item') && Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
            $ret = DB::table('orders')
                ->whereNotNull('Sales_Return_VoucherNo')
                ->where('Sales_Return_VoucherNo', '!=', '')
                ->whereDate('Sales_Return_Dt', '<', $from);
            $this->scope($ret, 'buyer_userid', $ids);
            $retOrders = $ret->select('order_id', 'buyer_userid')->get();
            if ($retOrders->isNotEmpty()) {
                $retTotals = DB::table('orders_item')
                    ->whereIn('order_id', $retOrders->pluck('order_id')->all())
                    ->groupBy('order_id')
                    ->select('order_id', DB::raw('COALESCE(SUM(qty_returned * item_price), 0) as t'))
                    ->pluck('t', 'order_id');
                foreach ($retOrders as $o) {
                    $open[(int) $o->buyer_userid] = ($open[(int) $o->buyer_userid] ?? 0) - (float) ($retTotals[$o->order_id] ?? 0);
                }
            }
        }

        foreach ($this->voucherOpening('CUSTOMER', $from, $ids, payable: false) as $acc => $val) {
            $open[$acc] = ($open[$acc] ?? 0) + $val;
        }

        return $open;
    }

    /** @return array<int,float> account_id => opening (Cr - Dr). */
    private function supplierOpening(?string $from, array $ids): array
    {
        if ($from === null) {
            return [];
        }
        $open = [];

        if (Schema::hasTable('purchase_vouchers')) {
            $inv = DB::table('purchase_vouchers')
                ->where('status', 'POSTED')
                ->whereDate('doc_date', '<', $from)
                ->whereRaw('COALESCE(net_total, 0) > 0');
            $this->scope($inv, 'supplier_id', $ids);
            foreach ($inv->groupBy('supplier_id')->select('supplier_id', DB::raw('SUM(net_total) as t'))->get() as $r) {
                $open[(int) $r->supplier_id] = ($open[(int) $r->supplier_id] ?? 0) + (float) $r->t;
            }
        }

        // Purchase returns before `from` debit the supplier (reduce the payable opening).
        if (Schema::hasTable('purchase_returns')) {
            $ret = DB::table('purchase_returns')
                ->where('status', 'POSTED')
                ->whereDate('doc_date', '<', $from)
                ->whereRaw('COALESCE(net_total, 0) > 0');
            $this->scope($ret, 'supplier_id', $ids);
            foreach ($ret->groupBy('supplier_id')->select('supplier_id', DB::raw('SUM(net_total) as t'))->get() as $r) {
                $open[(int) $r->supplier_id] = ($open[(int) $r->supplier_id] ?? 0) - (float) $r->t;
            }
        }

        foreach ($this->voucherOpening('SUPPLIER', $from, $ids, payable: true) as $acc => $val) {
            $open[$acc] = ($open[$acc] ?? 0) + $val;
        }

        return $open;
    }

    /** @return array<int,float> account_id => signed opening from ledger_entries before `from`. */
    private function voucherOpening(string $source, ?string $from, array $ids, bool $payable): array
    {
        if ($from === null) {
            return [];
        }
        $expr = $payable ? 'SUM(le.cr_amount - le.dr_amount)' : 'SUM(le.dr_amount - le.cr_amount)';
        $q = DB::table('ledger_entries as le')
            ->join('vouchers as v', 'v.id', '=', 'le.voucher_id')
            ->where('le.ledger_source', $source)
            ->where('v.status', 'POSTED')
            ->whereDate('le.entry_date', '<', $from);
        $this->scope($q, 'le.ledger_id', $ids);

        return $q->groupBy('le.ledger_id')
            ->select('le.ledger_id', DB::raw($expr . ' as t'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->ledger_id => (float) $r->t])
            ->all();
    }

    // ── Names ───────────────────────────────────────────────────────────────────

    /** @return array<int,array{name:?string,sub:?string}> */
    private function names(string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return match ($type) {
            'CUSTOMER' => DB::table('user')->whereIn('userid', $ids)
                ->get(['userid', 'name', 'shop_name', 'contactno'])
                ->mapWithKeys(fn ($u) => [(int) $u->userid => [
                    'name' => $u->shop_name ?: ($u->name ?: null),
                    'sub' => $u->contactno,
                ]])->all(),
            'SUPPLIER' => DB::table('suppliers')->whereIn('id', $ids)
                ->get(['id', 'supplier_name', 'phone'])
                ->mapWithKeys(fn ($s) => [(int) $s->id => [
                    'name' => $s->supplier_name,
                    'sub' => $s->phone,
                ]])->all(),
            default => DB::table('general_account')->whereIn('id', $ids)
                ->get(['id', 'account_name', 'account_type'])
                ->mapWithKeys(fn ($a) => [(int) $a->id => [
                    'name' => $a->account_name,
                    'sub' => $a->account_type,
                ]])->all(),
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function between($q, string $col, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $q->whereDate($col, '>=', $from);
        }
        if ($to !== null) {
            $q->whereDate($col, '<=', $to);
        }
    }

    private function scope($q, string $col, array $ids): void
    {
        if (! empty($ids)) {
            $q->whereIn($col, $ids);
        }
    }

    private function voucherParticular(string $type): string
    {
        return match ($type) {
            'CR' => 'Cash Receipt',
            'BR' => 'Bank Receipt',
            'CP' => 'Cash Payment',
            'BP' => 'Bank Payment',
            'CN' => 'Credit Note',
            'DN' => 'Debit Note',
            'JV' => 'Journal Voucher',
            default => $type,
        };
    }

    private function drCr(float $signed, bool $payable): string
    {
        // For payable accounts the running value is already (Cr - Dr): positive => Cr.
        if ($payable) {
            return $signed >= 0 ? 'Cr' : 'Dr';
        }
        return $signed >= 0 ? 'Dr' : 'Cr';
    }

    /** @return array{balance:float,balance_dr_cr:string} */
    private function balanceFields(float $signed, bool $payable): array
    {
        return [
            'balance' => round(abs($signed), 2),
            'balance_dr_cr' => $this->drCr($signed, $payable),
        ];
    }
}
