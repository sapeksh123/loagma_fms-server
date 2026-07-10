<?php

namespace App\Services;

use App\Models\BillAdjustment;
use App\Models\GeneralAccount;
use App\Models\LedgerEntry;
use App\Models\PartyAdvance;
use App\Models\Voucher;
use App\Models\VoucherDetail;
use App\Models\VoucherPdcDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Owns ALL transactional accounting logic for CP/BP/CR/BR vouchers.
 *
 * Rules (v1):
 *  - One detail row => one bill (AGAINST_REF) or an advance (ON_ACCOUNT).
 *  - Auto-post: a saved voucher is always POSTED and immediately writes
 *    ledger_entries + bill_adjustments + party_advances in one DB transaction.
 *  - No discount / no auto-allocation in v1.
 */
class VoucherPostingService
{
    // PDC = post-dated cheque issued (payment-like), PDR = post-dated cheque
    // received (receipt-like). Both post straight to the real Bank ledger at
    // entry time, same as BP/BR; voucher_pdc_details only tracks the physical
    // cheque's pending/cleared/bounced lifecycle for the PDC Outstanding Report.
    public const TYPES = ['CP', 'BP', 'CR', 'BR', 'CN', 'DN', 'JV', 'PDC', 'PDR'];

    /**
     * Voucher type => required account_type of the header ledger. CP/CR are Cash;
     * BP/BR/PDC/PDR are Bank (a cheque always moves through a bank account);
     * CN/DN have no entry here and accept ANY general account.
     */
    public const HEADER_ACCOUNT_TYPE = [
        'CP' => 'Cash',
        'CR' => 'Cash',
        'BP' => 'Bank',
        'BR' => 'Bank',
        'PDC' => 'Bank',
        'PDR' => 'Bank',
    ];

    /**
     * Payment vouchers debit the party/expense; receipts credit the party/income.
     * Debit Note debits the party (payment-like); Credit Note credits it (receipt-like).
     * PDC (cheque issued) is payment-like; PDR (cheque received) is receipt-like.
     */
    public const PAYMENT_TYPES = ['CP', 'BP', 'DN', 'PDC'];
    public const RECEIPT_TYPES = ['CR', 'BR', 'CN', 'PDR'];

    /** Voucher types whose header/detail carries post-dated cheque metadata. */
    public const PDC_TYPES = ['PDC', 'PDR'];

    /** Detail categories allowed per voucher kind (legacy; all are now allowed everywhere). */
    public const PAYMENT_CATEGORIES = ['SUPPLIER', 'GENERAL'];
    public const RECEIPT_CATEGORIES = ['CUSTOMER', 'GENERAL'];

    /**
     * Voucher types that *settle* (reduce the balance of) each invoice type. The
     * opposite direction is a refund and re-opens the bill (negative settlement):
     *   SALES (customer)            -> settled by Receipts (CR/BR), Credit Notes (CN), PDR
     *   PURCHASE (supplier)         -> settled by Payments (CP/BP), Debit Notes (DN), PDC
     *   SALES_RETURN (customer)     -> a credit we owe the customer; settled by Payments (CP/BP/PDC)
     *   PURCHASE_RETURN (supplier)  -> a credit the supplier owes us; settled by Receipts (CR/BR/PDR)
     */
    public const SETTLING_TYPES = [
        'SALES' => ['CR', 'BR', 'CN', 'PDR'],
        'PURCHASE' => ['CP', 'BP', 'DN', 'PDC'],
        'SALES_RETURN' => ['CP', 'BP', 'PDC'],
        'PURCHASE_RETURN' => ['CR', 'BR', 'PDR'],
    ];

    public function isPayment(string $type): bool
    {
        return in_array($type, self::PAYMENT_TYPES, true);
    }

    /** True when $type settles (vs refunds) the given invoice type. */
    public function isSettlingDirection(string $type, string $invoiceType): bool
    {
        return in_array($type, self::SETTLING_TYPES[$invoiceType] ?? [], true);
    }

    /**
     * SQL aggregate summing AGAINST_REF adjusted_amount with refunds negated, for
     * the given invoice type. Queries using this must join `vouchers as v` and
     * `bill_adjustments as ba`. Voucher-type lists are hard-coded constants (no
     * injection risk).
     */
    public static function signedPaidExpr(string $invoiceType): string
    {
        $settling = self::SETTLING_TYPES[$invoiceType] ?? [];
        $list = "'" . implode("','", $settling) . "'";

        return "SUM(CASE WHEN v.voucher_type IN ($list) THEN ba.adjusted_amount ELSE -ba.adjusted_amount END)";
    }

    /**
     * Create and post a voucher. Returns the persisted Voucher (with id).
     *
     * @param array $data validated payload (see VoucherController::store)
     */
    public function create(array $data): Voucher
    {
        return DB::transaction(function () use ($data) {
            $type = $data['voucher_type'];
            $date = $data['voucher_date'];
            $fy = $this->financialYear($date);
            $seq = $this->nextSeq($type, $fy);

            $voucher = Voucher::create([
                'voucher_type' => $type,
                'voucher_no' => $this->formatVoucherNo($type, $fy, $seq),
                'fy' => $fy,
                'seq' => $seq,
                'voucher_date' => $date,
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'total_amount' => 0,
                'narration' => $data['narration'] ?? null,
                'status' => 'POSTED',
                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['created_by'] ?? null,
            ]);

            $this->postLines($voucher, $data['details']);

            if (in_array($type, self::PDC_TYPES, true)) {
                $this->upsertPdcDetail($voucher, $data);
            }

            return $voucher->fresh(['details', 'ledgerEntries', 'pdcDetail']);
        });
    }

    /**
     * Edit = reverse + repost in one transaction, keeping the same voucher_no/seq.
     */
    public function updateVoucher(Voucher $voucher, array $data): Voucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            // Reverse: cascade deletes voucher_details -> bill_adjustments / party_advances.
            $voucher->details()->delete();
            $voucher->ledgerEntries()->delete();

            $voucher->update([
                'voucher_date' => $data['voucher_date'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'narration' => $data['narration'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
                'total_amount' => 0,
            ]);

            $this->postLines($voucher, $data['details']);

            if (in_array($voucher->voucher_type, self::PDC_TYPES, true)) {
                $this->upsertPdcDetail($voucher, $data);
            }

            return $voucher->fresh(['details', 'ledgerEntries', 'pdcDetail']);
        });
    }

    public function deleteVoucher(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            // FK cascade removes details, bill_adjustments, party_advances, ledger_entries,
            // and voucher_pdc_details (PDC/PDR only).
            $voucher->delete();
        });
    }

    /**
     * Create or refresh the cheque metadata row for a PDC/PDR voucher. The
     * cheque amount itself is posted straight to the real Bank ledger by
     * postLines() (same as BP/BR) at entry time; this row only tracks the
     * physical cheque's pending/cleared/bounced lifecycle for the PDC
     * Outstanding Report. Existing status is left untouched on edit.
     */
    private function upsertPdcDetail(Voucher $voucher, array $data): void
    {
        VoucherPdcDetail::updateOrCreate(
            ['voucher_id' => $voucher->id],
            [
                'cheque_no' => $data['cheque_no'],
                'cheque_date' => $data['cheque_date'],
                'bank_name' => $data['bank_name'] ?? null,
            ]
        );
    }

    /**
     * Mark a PDC/PDR cheque as cleared (successfully presented at the bank).
     * The cash/bank ledger already reflects the amount from entry time, so no
     * further posting is needed here — only the cheque's own status changes.
     */
    public function clearPdc(Voucher $voucher, string $clearDate): void
    {
        $detail = $voucher->pdcDetail;
        if ($detail === null) {
            throw ValidationException::withMessages(['pdc' => ['This voucher has no cheque detail.']]);
        }
        if ($detail->status !== 'PENDING') {
            throw ValidationException::withMessages(['pdc' => ["Cheque is already {$detail->status}."]]);
        }

        $detail->update(['status' => 'CLEARED', 'cleared_date' => $clearDate]);
    }

    /**
     * Mark a PDC/PDR cheque as bounced: reverses its ledger effect (reopens the
     * settled bill / restores the party balance) exactly like deleting the
     * voucher, but keeps the voucher and cheque record for audit with a
     * BOUNCED / CANCELLED status instead of removing them.
     */
    public function bouncePdc(Voucher $voucher, string $bounceDate): void
    {
        $detail = $voucher->pdcDetail;
        if ($detail === null) {
            throw ValidationException::withMessages(['pdc' => ['This voucher has no cheque detail.']]);
        }
        if ($detail->status !== 'PENDING') {
            throw ValidationException::withMessages(['pdc' => ["Cheque is already {$detail->status}."]]);
        }

        DB::transaction(function () use ($voucher, $detail, $bounceDate) {
            $voucher->details()->delete();
            $voucher->ledgerEntries()->delete();
            $voucher->update(['status' => 'CANCELLED', 'total_amount' => 0]);
            $detail->update(['status' => 'BOUNCED', 'bounced_date' => $bounceDate]);
        });
    }

    /**
     * Build voucher_details + ledger_entries + bill_adjustments + party_advances
     * for the given detail payload, then assert the entry is balanced.
     */
    private function postLines(Voucher $voucher, array $details): void
    {
        $type = $voucher->voucher_type;

        // Journal Vouchers carry per-row Dr/Cr verbatim (no header, no bills).
        if ($type === 'JV') {
            $this->postJournalLines($voucher, $details);

            return;
        }

        $date = $voucher->voucher_date instanceof \DateTimeInterface
            ? $voucher->voucher_date->format('Y-m-d')
            : (string) $voucher->voucher_date;

        $isPayment = $this->isPayment($type);
        $total = 0.0;

        foreach ($details as $line) {
            $category = $line['account_category'];
            $accountId = (int) $line['account_id'];
            $amount = round((float) $line['amount'], 2);
            $total += $amount;

            $detail = VoucherDetail::create([
                'voucher_id' => $voucher->id,
                'account_category' => $category,
                'account_id' => $accountId,
                'amount' => $amount,
                'narration' => $line['narration'] ?? null,
            ]);

            // Detail-side ledger entry: payments Dr the party/expense, receipts Cr it.
            LedgerEntry::create([
                'voucher_id' => $voucher->id,
                'ledger_source' => $category,
                'ledger_id' => $accountId,
                'dr_amount' => $isPayment ? $amount : 0,
                'cr_amount' => $isPayment ? 0 : $amount,
                'entry_date' => $date,
            ]);

            // Bill-wise settlement only for party rows (CUSTOMER/SUPPLIER).
            if ($category === 'GENERAL') {
                continue;
            }

            // Base invoice type for this party (used for the on-account remainder).
            $baseInvoiceType = $category === 'CUSTOMER' ? 'SALES' : 'PURCHASE';

            // A single row's amount may be allocated across many bills; any
            // unallocated remainder becomes an on-account advance. Each allocation
            // carries its own invoice_type (e.g. SALES vs SALES_RETURN) so a single
            // party row can settle both an invoice and a return.
            $allocations = $line['allocations'] ?? [];
            $allocated = 0.0;
            foreach ($allocations as $alloc) {
                $aAmt = round((float) $alloc['amount'], 2);
                if ($aAmt <= 0) {
                    continue;
                }
                $allocated += $aAmt;
                BillAdjustment::create([
                    'voucher_detail_id' => $detail->id,
                    'invoice_type' => $alloc['invoice_type'] ?? $baseInvoiceType,
                    'invoice_id' => (int) $alloc['invoice_id'],
                    'adjustment_type' => 'AGAINST_REF',
                    'adjusted_amount' => $aAmt,
                    'discount_amount' => 0,
                ]);
            }

            $remainder = round($amount - $allocated, 2);
            if ($remainder > 0.001) {
                BillAdjustment::create([
                    'voucher_detail_id' => $detail->id,
                    'invoice_type' => $baseInvoiceType,
                    'invoice_id' => 0,
                    'adjustment_type' => 'ON_ACCOUNT',
                    'adjusted_amount' => $remainder,
                    'discount_amount' => 0,
                ]);
                PartyAdvance::create([
                    'party_type' => $category,
                    'party_id' => $accountId,
                    'voucher_detail_id' => $detail->id,
                    'amount' => $remainder,
                    'remaining_amount' => $remainder,
                ]);
            }
        }

        $total = round($total, 2);

        // Header cash/bank ledger entry: payments Cr cash/bank, receipts Dr it.
        LedgerEntry::create([
            'voucher_id' => $voucher->id,
            'ledger_source' => 'GENERAL',
            'ledger_id' => $voucher->cash_bank_account_id,
            'dr_amount' => $isPayment ? 0 : $total,
            'cr_amount' => $isPayment ? $total : 0,
            'entry_date' => $date,
        ]);

        $voucher->update(['total_amount' => $total]);

        $this->assertBalanced($voucher->id);
    }

    /**
     * Post a Journal Voucher: each row is independently a Debit OR a Credit and is
     * written to the ledger verbatim. No cash/bank header entry, and no bill
     * adjustments / advances — a JV is ledger-only. Validation (exactly one of
     * dr/cr per row, balanced totals, >=2 rows) is enforced in the controller.
     */
    private function postJournalLines(Voucher $voucher, array $details): void
    {
        $date = $voucher->voucher_date instanceof \DateTimeInterface
            ? $voucher->voucher_date->format('Y-m-d')
            : (string) $voucher->voucher_date;

        $total = 0.0; // sum of debits (== sum of credits once balanced)

        foreach ($details as $line) {
            $category = $line['account_category'];
            $accountId = (int) $line['account_id'];
            $dr = round((float) ($line['dr_amount'] ?? 0), 2);
            $cr = round((float) ($line['cr_amount'] ?? 0), 2);
            $total += $dr;

            VoucherDetail::create([
                'voucher_id' => $voucher->id,
                'account_category' => $category,
                'account_id' => $accountId,
                // voucher_details.amount holds the line magnitude (the non-zero side).
                'amount' => $dr > 0 ? $dr : $cr,
                'narration' => $line['narration'] ?? null,
            ]);

            LedgerEntry::create([
                'voucher_id' => $voucher->id,
                'ledger_source' => $category,
                'ledger_id' => $accountId,
                'dr_amount' => $dr,
                'cr_amount' => $cr,
                'entry_date' => $date,
            ]);
        }

        $voucher->update(['total_amount' => round($total, 2)]);

        $this->assertBalanced($voucher->id);
    }

    private function assertBalanced(int $voucherId): void
    {
        $sums = LedgerEntry::where('voucher_id', $voucherId)
            ->selectRaw('COALESCE(SUM(dr_amount),0) as dr, COALESCE(SUM(cr_amount),0) as cr')
            ->first();

        if (round((float) $sums->dr, 2) !== round((float) $sums->cr, 2)) {
            throw ValidationException::withMessages([
                'details' => ['Voucher is not balanced (Dr != Cr). Posting aborted.'],
            ]);
        }
    }

    // ── Numbering ────────────────────────────────────────────────────────────

    /** FY label for a date, Apr-Mar. e.g. 2025-06-01 => "25-26". */
    public function financialYear(string $date): string
    {
        $ts = strtotime($date);
        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $startYear = $month >= 4 ? $year : $year - 1;

        return sprintf('%02d-%02d', $startYear % 100, ($startYear + 1) % 100);
    }

    public function formatVoucherNo(string $type, string $fy, int $seq): string
    {
        // Plain sequence, no zero padding (e.g. CR/26-27/3, not CR/26-27/0003).
        return sprintf('%s/%s/%d', $type, $fy, $seq);
    }

    private function nextSeq(string $type, string $fy): int
    {
        $max = Voucher::where('voucher_type', $type)
            ->where('fy', $fy)
            ->lockForUpdate()
            ->max('seq');

        return (int) $max + 1;
    }

    /** Preview the next voucher number for a type+date (no row locked). */
    public function previewNextNo(string $type, string $date): array
    {
        $fy = $this->financialYear($date);
        $max = Voucher::where('voucher_type', $type)->where('fy', $fy)->max('seq');
        $seq = (int) $max + 1;

        return [
            'voucher_type' => $type,
            'fy' => $fy,
            'seq' => $seq,
            'voucher_no' => $this->formatVoucherNo($type, $fy, $seq),
        ];
    }

    // ── Outstanding helpers (also used for validation) ─────────────────────────

    /**
     * Gross total of a billable document for the given invoice type, or null:
     *   SALES            -> orders.order_total
     *   SALES_RETURN     -> SUM(orders_item.qty_returned * item_price) for the order
     *   PURCHASE         -> purchase_vouchers.net_total
     *   PURCHASE_RETURN  -> purchase_returns.net_total
     */
    public function invoiceTotal(string $invoiceType, int $invoiceId): ?float
    {
        if ($invoiceType === 'SALES') {
            $total = DB::table('orders')->where('order_id', $invoiceId)->value('order_total');
        } elseif ($invoiceType === 'SALES_RETURN') {
            return $this->salesReturnTotal($invoiceId);
        } elseif ($invoiceType === 'PURCHASE_RETURN') {
            if (! Schema::hasTable('purchase_returns')) {
                return null;
            }
            $total = DB::table('purchase_returns')->where('id', $invoiceId)->value('net_total');
        } else {
            if (! Schema::hasTable('purchase_vouchers')) {
                return null;
            }
            $total = DB::table('purchase_vouchers')->where('id', $invoiceId)->value('net_total');
        }

        return $total === null ? null : round((float) $total, 2);
    }

    /**
     * Value of a sales return recorded on an order = SUM(qty_returned * item_price)
     * across its items. Returns null when the order has no return marker, so callers
     * can distinguish "not a return" from a zero-value return.
     */
    public function salesReturnTotal(int $orderId): ?float
    {
        if (! Schema::hasColumn('orders', 'Sales_Return_VoucherNo') || ! Schema::hasTable('orders_item')) {
            return null;
        }

        $hasReturn = DB::table('orders')
            ->where('order_id', $orderId)
            ->whereNotNull('Sales_Return_VoucherNo')
            ->where('Sales_Return_VoucherNo', '!=', '')
            ->exists();
        if (! $hasReturn) {
            return null;
        }

        $total = DB::table('orders_item')
            ->where('order_id', $orderId)
            ->selectRaw('COALESCE(SUM(qty_returned * item_price), 0) as t')
            ->value('t');

        return round((float) $total, 2);
    }

    /**
     * Remaining balance of a single invoice = total - signed settled amount across
     * POSTED vouchers (refunds negated). Used to cap AGAINST_REF amounts.
     */
    public function invoiceOutstanding(string $invoiceType, int $invoiceId): ?float
    {
        $total = $this->invoiceTotal($invoiceType, $invoiceId);
        if ($total === null) {
            return null;
        }

        $row = DB::table('bill_adjustments as ba')
            ->join('voucher_details as vd', 'vd.id', '=', 'ba.voucher_detail_id')
            ->join('vouchers as v', 'v.id', '=', 'vd.voucher_id')
            ->where('ba.invoice_type', $invoiceType)
            ->where('ba.invoice_id', $invoiceId)
            ->where('ba.adjustment_type', 'AGAINST_REF')
            ->where('v.status', 'POSTED')
            ->selectRaw(self::signedPaidExpr($invoiceType) . ' as paid')
            ->first();

        return round($total - (float) ($row->paid ?? 0), 2);
    }

    public function invoiceBelongsToParty(string $category, int $accountId, string $invoiceType, int $invoiceId): bool
    {
        if ($category === 'CUSTOMER' && $invoiceType === 'SALES') {
            return DB::table('orders')
                ->where('order_id', $invoiceId)
                ->where('buyer_userid', $accountId)
                ->exists();
        }

        if ($category === 'CUSTOMER' && $invoiceType === 'SALES_RETURN') {
            if (! Schema::hasColumn('orders', 'Sales_Return_VoucherNo')) {
                return false;
            }
            return DB::table('orders')
                ->where('order_id', $invoiceId)
                ->where('buyer_userid', $accountId)
                ->whereNotNull('Sales_Return_VoucherNo')
                ->where('Sales_Return_VoucherNo', '!=', '')
                ->exists();
        }

        if ($category === 'SUPPLIER' && $invoiceType === 'PURCHASE') {
            if (! Schema::hasTable('purchase_vouchers')) {
                return false;
            }
            return DB::table('purchase_vouchers')
                ->where('id', $invoiceId)
                ->where('supplier_id', $accountId)
                ->exists();
        }

        if ($category === 'SUPPLIER' && $invoiceType === 'PURCHASE_RETURN') {
            if (! Schema::hasTable('purchase_returns')) {
                return false;
            }
            return DB::table('purchase_returns')
                ->where('id', $invoiceId)
                ->where('supplier_id', $accountId)
                ->where('status', 'POSTED')
                ->exists();
        }

        return false;
    }

    public function accountExists(string $category, int $accountId): bool
    {
        return match ($category) {
            'CUSTOMER' => DB::table('user')->where('userid', $accountId)->exists(),
            'SUPPLIER' => DB::table('suppliers')->where('id', $accountId)->exists(),
            'GENERAL' => GeneralAccount::whereKey($accountId)->exists(),
            default => false,
        };
    }

    public function isValidHeaderLedger(string $type, int $accountId): bool
    {
        $required = self::HEADER_ACCOUNT_TYPE[$type] ?? null;

        // CN/DN: any existing general account is allowed (Cash, Bank, Sales Return…).
        if ($required === null) {
            return in_array($type, self::TYPES, true)
                && GeneralAccount::whereKey($accountId)->exists();
        }

        return GeneralAccount::whereKey($accountId)
            ->where('account_type', $required)
            ->exists();
    }
}
