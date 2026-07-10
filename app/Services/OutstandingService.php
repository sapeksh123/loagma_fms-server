<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derived (never stored) bill-wise outstanding for customers and suppliers.
 *
 *   Customer outstanding = orders.order_total       - SUM(AGAINST_REF adjustments)
 *   Supplier outstanding = purchase_vouchers.net_total - SUM(AGAINST_REF adjustments)
 *
 * Opening balance policy (v1): every existing invoice's outstanding = full amount
 * minus NEW vouchers only (old/orphaned tables are ignored).
 */
class OutstandingService
{
    /** Per-invoice list for a customer. */
    public function customerBills(int $userId, bool $onlyOpen = true): array
    {
        $invoices = DB::table('orders')
            ->where('buyer_userid', $userId)
            ->whereRaw('COALESCE(order_total, 0) > 0')
            ->select('order_id', 'bill_no', 'Bill_Dt', 'order_total')
            ->orderBy('Bill_Dt')
            ->get();

        $paid = $this->paidMap('SALES', $invoices->pluck('order_id')->all());
        $rows = [];

        foreach ($invoices as $inv) {
            $total = (float) $inv->order_total;
            $p = $paid[(int) $inv->order_id] ?? 0.0;
            $balance = round($total - $p, 2);
            if ($onlyOpen && $balance <= 0.001) {
                continue;
            }
            $rows[] = [
                'invoice_type' => 'SALES',
                'invoice_id' => (int) $inv->order_id,
                'order_no' => (int) $inv->order_id,
                'bill_no' => $inv->bill_no ?: (string) $inv->order_id,
                'bill_date' => $inv->Bill_Dt,
                'total' => $total,
                'paid' => round($p, 2),
                'balance' => $balance,
            ];
        }

        // Sales returns (credits we owe the customer) as separate allocatable docs.
        foreach ($this->salesReturnRows($userId) as $r) {
            if ($onlyOpen && $r->balance <= 0.001) {
                continue;
            }
            $rows[] = [
                'invoice_type' => 'SALES_RETURN',
                'invoice_id' => $r->order_id,
                'order_no' => $r->order_id,
                'bill_no' => $r->voucher_no ?: (string) $r->order_id,
                'bill_date' => $r->date,
                'total' => $r->total,
                'paid' => $r->paid,
                'balance' => $r->balance,
            ];
        }

        return $rows;
    }

    /** Per-invoice list for a supplier. */
    public function supplierBills(int $supplierId, bool $onlyOpen = true): array
    {
        $rows = [];

        if (Schema::hasTable('purchase_vouchers')) {
            $invoices = DB::table('purchase_vouchers')
                ->where('supplier_id', $supplierId)
                ->where('status', 'POSTED')
                ->whereRaw('COALESCE(net_total, 0) > 0')
                ->select('id', 'doc_no', 'purchase_order_id', 'doc_date', 'bill_no', 'net_total')
                ->orderBy('doc_date')
                ->get();

            $paid = $this->paidMap('PURCHASE', $invoices->pluck('id')->all());

            foreach ($invoices as $inv) {
                $total = (float) $inv->net_total;
                $p = $paid[(int) $inv->id] ?? 0.0;
                $balance = round($total - $p, 2);
                if ($onlyOpen && $balance <= 0.001) {
                    continue;
                }
                $rows[] = [
                    'invoice_type' => 'PURCHASE',
                    'invoice_id' => (int) $inv->id,
                    'order_no' => $inv->purchase_order_id !== null ? (string) $inv->purchase_order_id : null,
                    'bill_no' => $inv->bill_no ?: ($inv->doc_no ?: (string) $inv->id),
                    'bill_date' => $inv->doc_date,
                    'total' => $total,
                    'paid' => round($p, 2),
                    'balance' => $balance,
                ];
            }
        }

        // Purchase returns (credits the supplier owes us) as separate allocatable docs.
        foreach ($this->purchaseReturnRows($supplierId) as $r) {
            if ($onlyOpen && $r->balance <= 0.001) {
                continue;
            }
            $rows[] = [
                'invoice_type' => 'PURCHASE_RETURN',
                'invoice_id' => $r->id,
                'order_no' => $r->source_purchase_voucher_id !== null ? (string) $r->source_purchase_voucher_id : null,
                'bill_no' => $r->doc_no ?: (string) $r->id,
                'bill_date' => $r->date,
                'total' => $r->total,
                'paid' => $r->paid,
                'balance' => $r->balance,
            ];
        }

        return $rows;
    }

    /** Per-customer outstanding summary (only parties with a balance). */
    public function customerOutstandingSummary(): array
    {
        $paidByUser = DB::table('bill_adjustments as ba')
            ->join('voucher_details as vd', 'vd.id', '=', 'ba.voucher_detail_id')
            ->join('vouchers as v', 'v.id', '=', 'vd.voucher_id')
            ->where('ba.invoice_type', 'SALES')
            ->where('ba.adjustment_type', 'AGAINST_REF')
            ->where('v.status', 'POSTED')
            ->groupBy('vd.account_id')
            ->select('vd.account_id', DB::raw(VoucherPostingService::signedPaidExpr('SALES') . ' as paid'))
            ->pluck('paid', 'account_id');

        $totals = DB::table('orders')
            ->whereRaw('COALESCE(order_total, 0) > 0')
            ->groupBy('buyer_userid')
            ->select('buyer_userid', DB::raw('SUM(order_total) as total'))
            ->get();

        // Open sales-return credits reduce each customer's net receivable.
        $returnOpenByUser = [];
        foreach ($this->salesReturnRows() as $r) {
            $returnOpenByUser[$r->buyer_userid] = ($returnOpenByUser[$r->buyer_userid] ?? 0) + $r->balance;
        }

        $rows = [];
        foreach ($totals as $t) {
            $uid = (int) $t->buyer_userid;
            // Lump return credits into "paid" so total - paid = net balance stays consistent.
            $paidEff = (float) ($paidByUser[$uid] ?? 0) + (float) ($returnOpenByUser[$uid] ?? 0);
            $balance = round((float) $t->total - $paidEff, 2);
            if ($balance <= 0.001) {
                continue;
            }
            $rows[] = [
                'party_type' => 'CUSTOMER',
                'party_id' => $uid,
                'party_name' => null,
                'total' => round((float) $t->total, 2),
                'paid' => round($paidEff, 2),
                'balance' => $balance,
            ];
        }

        // Resolve names in one query (avoid N+1).
        $names = DB::table('user')
            ->whereIn('userid', array_column($rows, 'party_id'))
            ->pluck('name', 'userid');
        foreach ($rows as &$row) {
            $row['party_name'] = $names[$row['party_id']] ?? null;
        }
        unset($row);

        usort($rows, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return $rows;
    }

    /** Per-supplier outstanding summary (only parties with a balance). */
    public function supplierOutstandingSummary(): array
    {
        if (! Schema::hasTable('purchase_vouchers')) {
            return [];
        }

        $paidBySupplier = DB::table('bill_adjustments as ba')
            ->join('voucher_details as vd', 'vd.id', '=', 'ba.voucher_detail_id')
            ->join('vouchers as v', 'v.id', '=', 'vd.voucher_id')
            ->where('ba.invoice_type', 'PURCHASE')
            ->where('ba.adjustment_type', 'AGAINST_REF')
            ->where('v.status', 'POSTED')
            ->groupBy('vd.account_id')
            ->select('vd.account_id', DB::raw(VoucherPostingService::signedPaidExpr('PURCHASE') . ' as paid'))
            ->pluck('paid', 'account_id');

        $totals = DB::table('purchase_vouchers')
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0')
            ->groupBy('supplier_id')
            ->select('supplier_id', DB::raw('SUM(net_total) as total'))
            ->get();

        // Open purchase-return credits reduce each supplier's net payable.
        $returnOpenBySupplier = [];
        foreach ($this->purchaseReturnRows() as $r) {
            $returnOpenBySupplier[$r->supplier_id] = ($returnOpenBySupplier[$r->supplier_id] ?? 0) + $r->balance;
        }

        $rows = [];
        foreach ($totals as $t) {
            $sid = (int) $t->supplier_id;
            $paidEff = (float) ($paidBySupplier[$sid] ?? 0) + (float) ($returnOpenBySupplier[$sid] ?? 0);
            $balance = round((float) $t->total - $paidEff, 2);
            if ($balance <= 0.001) {
                continue;
            }
            $rows[] = [
                'party_type' => 'SUPPLIER',
                'party_id' => $sid,
                'party_name' => null,
                'total' => round((float) $t->total, 2),
                'paid' => round($paidEff, 2),
                'balance' => $balance,
            ];
        }

        // Resolve names in one query (avoid N+1).
        $names = DB::table('suppliers')
            ->whereIn('id', array_column($rows, 'party_id'))
            ->pluck('supplier_name', 'id');
        foreach ($rows as &$row) {
            $row['party_name'] = $names[$row['party_id']] ?? null;
        }
        unset($row);

        usort($rows, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return $rows;
    }

    /**
     * @return array<int,float> invoice_id => signed paid total (AGAINST_REF, POSTED).
     * When $asOn is given, only vouchers dated on/before it count (true "as on" cutoff).
     */
    private function paidMap(string $invoiceType, array $invoiceIds, ?string $asOn = null): array
    {
        if (empty($invoiceIds)) {
            return [];
        }

        $query = DB::table('bill_adjustments as ba')
            ->join('voucher_details as vd', 'vd.id', '=', 'ba.voucher_detail_id')
            ->join('vouchers as v', 'v.id', '=', 'vd.voucher_id')
            ->where('ba.invoice_type', $invoiceType)
            ->where('ba.adjustment_type', 'AGAINST_REF')
            ->where('v.status', 'POSTED')
            ->whereIn('ba.invoice_id', $invoiceIds);

        if ($asOn !== null) {
            $query->where('v.voucher_date', '<=', $asOn);
        }

        return $query
            ->groupBy('ba.invoice_id')
            ->select('ba.invoice_id', DB::raw(VoucherPostingService::signedPaidExpr($invoiceType) . ' as paid'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->invoice_id => (float) $r->paid])
            ->all();
    }

    /**
     * Sales-return documents: one per order carrying a Sales_Return_VoucherNo. The
     * return value = SUM(orders_item.qty_returned * item_price); balance subtracts the
     * signed SALES_RETURN settlements (CP/BP refunds to the customer). Returns with no
     * returned-item value are skipped (nothing to settle).
     *
     * @return array<int,object> {order_id, buyer_userid, voucher_no, date, total, paid, balance}
     */
    private function salesReturnRows(?int $userId = null, ?string $asOn = null): array
    {
        // Legacy columns/tables (shared CRM DB). Absent in the test schema — no-op there.
        if (! Schema::hasTable('orders_item') || ! Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
            return [];
        }

        $q = DB::table('orders')
            ->whereNotNull('Sales_Return_VoucherNo')
            ->where('Sales_Return_VoucherNo', '!=', '');
        if ($userId !== null) {
            $q->where('buyer_userid', $userId);
        }
        if ($asOn !== null) {
            $q->whereDate('Sales_Return_Dt', '<=', $asOn);
        }
        $orders = $q->select('order_id', 'buyer_userid', 'Sales_Return_VoucherNo', 'Sales_Return_Dt')
            ->orderBy('Sales_Return_Dt')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $ids = $orders->pluck('order_id')->all();
        $totals = DB::table('orders_item')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id')
            ->select('order_id', DB::raw('COALESCE(SUM(qty_returned * item_price), 0) as t'))
            ->pluck('t', 'order_id');
        $paid = $this->paidMap('SALES_RETURN', $ids, $asOn);

        $rows = [];
        foreach ($orders as $o) {
            $total = round((float) ($totals[$o->order_id] ?? 0), 2);
            if ($total <= 0.001) {
                continue;
            }
            $p = round((float) ($paid[(int) $o->order_id] ?? 0), 2);
            $rows[] = (object) [
                'order_id' => (int) $o->order_id,
                'buyer_userid' => (int) $o->buyer_userid,
                'voucher_no' => $o->Sales_Return_VoucherNo,
                'date' => $o->Sales_Return_Dt,
                'total' => $total,
                'paid' => $p,
                'balance' => round($total - $p, 2),
            ];
        }

        return $rows;
    }

    /**
     * Purchase-return documents from `purchase_returns` (status POSTED). Balance
     * subtracts the signed PURCHASE_RETURN settlements (CR/BR receipts from supplier).
     *
     * @return array<int,object> {id, supplier_id, doc_no, source_purchase_voucher_id, date, total, paid, balance}
     */
    private function purchaseReturnRows(?int $supplierId = null, ?string $asOn = null): array
    {
        if (! Schema::hasTable('purchase_returns')) {
            return [];
        }

        $q = DB::table('purchase_returns')
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0');
        if ($supplierId !== null) {
            $q->where('supplier_id', $supplierId);
        }
        if ($asOn !== null) {
            $q->whereDate('doc_date', '<=', $asOn);
        }
        $returns = $q->select('id', 'supplier_id', 'doc_no', 'source_purchase_voucher_id', 'doc_date', 'net_total')
            ->orderBy('doc_date')
            ->get();

        if ($returns->isEmpty()) {
            return [];
        }

        $paid = $this->paidMap('PURCHASE_RETURN', $returns->pluck('id')->all(), $asOn);

        $rows = [];
        foreach ($returns as $r) {
            $total = round((float) $r->net_total, 2);
            $p = round((float) ($paid[(int) $r->id] ?? 0), 2);
            $rows[] = (object) [
                'id' => (int) $r->id,
                'supplier_id' => (int) $r->supplier_id,
                'doc_no' => $r->doc_no,
                'source_purchase_voucher_id' => $r->source_purchase_voucher_id,
                'date' => $r->doc_date,
                'total' => $total,
                'paid' => $p,
                'balance' => round($total - $p, 2),
            ];
        }

        return $rows;
    }

    /**
     * Tally-style "outstanding as on <date>" detail, grouped by party, pending only.
     * Only invoices dated on/before $asOn and payments on/before $asOn are counted.
     *
     * @param array<int,int> $accountIds limit to these parties (empty = all)
     * @return array{groups:array,report_total_amount:float,report_total_balance:float,party_count:int,bill_count:int,as_on:string}
     */
    public function outstandingDetail(string $partyType, string $asOn, array $accountIds = []): array
    {
        $groups = $partyType === 'CUSTOMER'
            ? $this->customerOutstandingDetail($asOn, $accountIds)
            : $this->supplierOutstandingDetail($asOn, $accountIds);

        // Highest balance first.
        usort($groups, fn ($a, $b) => $b['total_balance'] <=> $a['total_balance']);

        $billCount = array_sum(array_map(fn ($g) => count($g['bills']), $groups));

        return [
            'groups' => $groups,
            'report_total_amount' => round(array_sum(array_column($groups, 'total_amount')), 2),
            'report_total_balance' => round(array_sum(array_column($groups, 'total_balance')), 2),
            'party_count' => count($groups),
            'bill_count' => $billCount,
            'as_on' => $asOn,
        ];
    }

    /** @return array<int,array> per-customer groups of pending bills as on $asOn. */
    private function customerOutstandingDetail(string $asOn, array $accountIds): array
    {
        $q = DB::table('orders')
            ->whereRaw('COALESCE(order_total, 0) > 0')
            ->whereDate('Bill_Dt', '<=', $asOn);
        if (! empty($accountIds)) {
            $q->whereIn('buyer_userid', $accountIds);
        }
        $invoices = $q->select(
            'order_id', 'buyer_userid', 'bill_no', 'invoice_number', 'Bill_Dt', 'order_total', 'Bill_Narration'
        )->orderBy('Bill_Dt')->get();

        $paid = $this->paidMap('SALES', $invoices->pluck('order_id')->all(), $asOn);

        $byParty = [];
        foreach ($invoices as $inv) {
            $balance = round((float) $inv->order_total - ($paid[(int) $inv->order_id] ?? 0.0), 2);
            if ($balance <= 0.001) {
                continue;
            }
            $pid = (int) $inv->buyer_userid;
            $byParty[$pid] ??= [];
            $byParty[$pid][] = [
                'invoice_id' => (int) $inv->order_id,
                'voucher_no' => $inv->bill_no ?: ($inv->invoice_number ?: (string) $inv->order_id),
                'order_no' => (string) $inv->order_id,
                'doc_date' => $inv->Bill_Dt,
                'amount' => round((float) $inv->order_total, 2),
                'adjustments' => round($paid[(int) $inv->order_id] ?? 0.0, 2),
                'balance' => $balance,
                'overdue_days' => $this->daysBetween($inv->Bill_Dt, $asOn),
                'narration' => $inv->Bill_Narration,
            ];
        }

        // Net open sales-return credits (shown as negative bills) into parties that
        // already have pending invoices, so the group balance reflects them.
        foreach ($this->salesReturnRows(null, $asOn) as $r) {
            $pid = $r->buyer_userid;
            if (! isset($byParty[$pid]) || $r->balance <= 0.001) {
                continue;
            }
            $byParty[$pid][] = [
                'invoice_id' => $r->order_id,
                'voucher_no' => $r->voucher_no,
                'order_no' => (string) $r->order_id,
                'doc_date' => $r->date,
                'amount' => -$r->total,
                'adjustments' => -$r->paid,
                'balance' => -$r->balance,
                'overdue_days' => $this->daysBetween($r->date, $asOn),
                'narration' => 'Sales Return',
            ];
        }

        $names = DB::table('user')
            ->whereIn('userid', array_keys($byParty))
            ->get(['userid', 'name', 'shop_name', 'shop_address', 'address', 'contactno'])
            ->keyBy('userid');

        $groups = [];
        foreach ($byParty as $pid => $bills) {
            $u = $names[$pid] ?? null;
            $groups[] = [
                'party_id' => $pid,
                'party_name' => $u?->shop_name ?: ($u?->name ?: 'Customer #' . $pid),
                'contact_person' => $u?->name,
                'address' => $u?->shop_address ?: ($u?->address ?? null),
                'phone' => $u?->contactno,
                'bills' => $bills,
                'total_amount' => round(array_sum(array_column($bills, 'amount')), 2),
                'total_balance' => round(array_sum(array_column($bills, 'balance')), 2),
            ];
        }

        return $groups;
    }

    /** @return array<int,array> per-supplier groups of pending bills as on $asOn. */
    private function supplierOutstandingDetail(string $asOn, array $accountIds): array
    {
        if (! Schema::hasTable('purchase_vouchers')) {
            return [];
        }

        $q = DB::table('purchase_vouchers')
            ->where('status', 'POSTED')
            ->whereRaw('COALESCE(net_total, 0) > 0')
            ->whereDate('doc_date', '<=', $asOn);
        if (! empty($accountIds)) {
            $q->whereIn('supplier_id', $accountIds);
        }
        $invoices = $q->select(
            'id', 'supplier_id', 'doc_no', 'bill_no', 'purchase_order_id', 'doc_date', 'net_total', 'narration'
        )->orderBy('doc_date')->get();

        $paid = $this->paidMap('PURCHASE', $invoices->pluck('id')->all(), $asOn);

        $byParty = [];
        foreach ($invoices as $inv) {
            $balance = round((float) $inv->net_total - ($paid[(int) $inv->id] ?? 0.0), 2);
            if ($balance <= 0.001) {
                continue;
            }
            $pid = (int) $inv->supplier_id;
            $byParty[$pid] ??= [];
            $byParty[$pid][] = [
                'invoice_id' => (int) $inv->id,
                'voucher_no' => $inv->bill_no ?: ($inv->doc_no ?: (string) $inv->id),
                'order_no' => $inv->purchase_order_id !== null ? (string) $inv->purchase_order_id : null,
                'doc_date' => $inv->doc_date,
                'amount' => round((float) $inv->net_total, 2),
                'adjustments' => round($paid[(int) $inv->id] ?? 0.0, 2),
                'balance' => $balance,
                'overdue_days' => $this->daysBetween($inv->doc_date, $asOn),
                'narration' => $inv->narration,
            ];
        }

        // Net open purchase-return credits (shown as negative bills) into suppliers
        // that already have pending invoices.
        foreach ($this->purchaseReturnRows(null, $asOn) as $r) {
            $pid = $r->supplier_id;
            if (! isset($byParty[$pid]) || $r->balance <= 0.001) {
                continue;
            }
            $byParty[$pid][] = [
                'invoice_id' => $r->id,
                'voucher_no' => $r->doc_no,
                'order_no' => $r->source_purchase_voucher_id !== null ? (string) $r->source_purchase_voucher_id : null,
                'doc_date' => $r->date,
                'amount' => -$r->total,
                'adjustments' => -$r->paid,
                'balance' => -$r->balance,
                'overdue_days' => $this->daysBetween($r->date, $asOn),
                'narration' => 'Purchase Return',
            ];
        }

        $sup = DB::table('suppliers')
            ->whereIn('id', array_keys($byParty))
            ->get(['id', 'supplier_name', 'contact_person', 'address_line1', 'city', 'phone'])
            ->keyBy('id');

        $groups = [];
        foreach ($byParty as $pid => $bills) {
            $s = $sup[$pid] ?? null;
            $address = $s ? trim(implode(', ', array_filter([$s->address_line1, $s->city]))) : null;
            $groups[] = [
                'party_id' => $pid,
                'party_name' => $s?->supplier_name ?: 'Supplier #' . $pid,
                'contact_person' => $s?->contact_person,
                'address' => $address ?: null,
                'phone' => $s?->phone,
                'bills' => $bills,
                'total_amount' => round(array_sum(array_column($bills, 'amount')), 2),
                'total_balance' => round(array_sum(array_column($bills, 'balance')), 2),
            ];
        }

        return $groups;
    }

    /** Whole days between an invoice date and the as-on date (min 0). */
    private function daysBetween(?string $docDate, string $asOn): int
    {
        if ($docDate === null || $docDate === '') {
            return 0;
        }
        $from = strtotime($docDate);
        $to = strtotime($asOn);
        if ($from === false || $to === false) {
            return 0;
        }

        return max(0, (int) floor(($to - $from) / 86400));
    }
}
