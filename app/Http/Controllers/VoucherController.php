<?php

namespace App\Http\Controllers;

use App\Models\GeneralAccount;
use App\Models\Voucher;
use App\Services\VoucherPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    public function __construct(private VoucherPostingService $posting)
    {
    }

    public function index(Request $request)
    {
        $type = strtoupper(trim((string) $request->get('type', '')));
        $search = trim((string) $request->get('search', ''));
        $page = max((int) $request->get('page', 1), 1);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 200);

        $query = Voucher::query();

        if (in_array($type, VoucherPostingService::TYPES, true)) {
            $query->where('voucher_type', $type);
        }
        if ($search !== '') {
            $query->where('voucher_no', 'like', "%{$search}%");
        }
        if ($from = $request->get('from')) {
            $query->whereDate('voucher_date', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('voucher_date', '<=', $to);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $cashBankNames = $this->generalAccountNames($rows->pluck('cash_bank_account_id')->all());

        $data = $rows->map(fn (Voucher $v) => [
            'id' => $v->id,
            'voucher_type' => $v->voucher_type,
            'voucher_no' => $v->voucher_no,
            'voucher_date' => $this->dateStr($v->voucher_date),
            'cash_bank_account_id' => $v->cash_bank_account_id,
            'cash_bank_account_name' => $cashBankNames[$v->cash_bank_account_id] ?? null,
            'total_amount' => (float) $v->total_amount,
            'narration' => $v->narration,
            'status' => $v->status,
        ])->values();

        return response()->json([
            'status' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function show(int $id)
    {
        $voucher = Voucher::with(['details.billAdjustments', 'ledgerEntries'])->find($id);

        if (! $voucher) {
            return response()->json(['status' => false, 'message' => 'Voucher not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $this->formatVoucher($voucher)]);
    }

    public function nextNo(Request $request)
    {
        $type = strtoupper(trim((string) $request->get('type', '')));
        if (! in_array($type, VoucherPostingService::TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'Invalid voucher type'], 422);
        }
        $date = (string) $request->get('date', date('Y-m-d'));

        return response()->json(['status' => true, 'data' => $this->posting->previewNextNo($type, $date)]);
    }

    /**
     * Locate a voucher by its type + financial year + sequence number.
     * GET /api/vouchers/find?type=CP&fy=26-27&seq=9
     */
    public function find(Request $request)
    {
        $type = strtoupper(trim((string) $request->get('type', '')));
        if (! in_array($type, VoucherPostingService::TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'Invalid voucher type'], 422);
        }
        $fy = trim((string) $request->get('fy', ''));
        $seq = (int) $request->get('seq', 0);
        if ($fy === '' || $seq <= 0) {
            return response()->json(['status' => false, 'message' => 'fy and seq are required'], 422);
        }

        $v = Voucher::where('voucher_type', $type)->where('fy', $fy)->where('seq', $seq)->first();

        return response()->json([
            'status' => true,
            'data' => $v ? ['id' => $v->id, 'voucher_no' => $v->voucher_no] : null,
        ]);
    }

    /**
     * Previous / next voucher of the same type, ordered by (fy, seq).
     * GET /api/vouchers/adjacent?type=CP&id={currentId}&dir=prev|next
     * With no id (new entry): prev = newest voucher, next = none.
     */
    public function adjacent(Request $request)
    {
        $type = strtoupper(trim((string) $request->get('type', '')));
        if (! in_array($type, VoucherPostingService::TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'Invalid voucher type'], 422);
        }
        $dir = $request->get('dir') === 'next' ? 'next' : 'prev';
        $currentId = (int) $request->get('id', 0);
        $current = $currentId > 0 ? Voucher::where('voucher_type', $type)->find($currentId) : null;

        if ($current === null) {
            $v = $dir === 'prev'
                ? Voucher::where('voucher_type', $type)->orderByDesc('fy')->orderByDesc('seq')->first()
                : null;
        } elseif ($dir === 'prev') {
            $v = Voucher::where('voucher_type', $type)
                ->where(function ($q) use ($current) {
                    $q->where('fy', '<', $current->fy)
                        ->orWhere(fn ($qq) => $qq->where('fy', $current->fy)->where('seq', '<', $current->seq));
                })
                ->orderByDesc('fy')->orderByDesc('seq')->first();
        } else {
            $v = Voucher::where('voucher_type', $type)
                ->where(function ($q) use ($current) {
                    $q->where('fy', '>', $current->fy)
                        ->orWhere(fn ($qq) => $qq->where('fy', $current->fy)->where('seq', '>', $current->seq));
                })
                ->orderBy('fy')->orderBy('seq')->first();
        }

        return response()->json([
            'status' => true,
            'data' => $v ? ['id' => $v->id, 'voucher_no' => $v->voucher_no] : null,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validatePayload($request);
            $voucher = $this->posting->create($data);

            return response()->json([
                'status' => true,
                'message' => 'Voucher posted successfully',
                'data' => $this->formatVoucher($voucher->load(['details.billAdjustments', 'ledgerEntries'])),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $voucher = Voucher::find($id);
        if (! $voucher) {
            return response()->json(['status' => false, 'message' => 'Voucher not found'], 404);
        }

        try {
            // Voucher type is immutable on edit; validate against the existing type.
            $data = $this->validatePayload($request, $voucher->voucher_type, $voucher->id);
            $data['updated_by'] = $data['created_by'] ?? null;
            $voucher = $this->posting->updateVoucher($voucher, $data);

            return response()->json([
                'status' => true,
                'message' => 'Voucher updated successfully',
                'data' => $this->formatVoucher($voucher->load(['details.billAdjustments', 'ledgerEntries'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id)
    {
        $voucher = Voucher::find($id);
        if (! $voucher) {
            return response()->json(['status' => false, 'message' => 'Voucher not found'], 404);
        }

        $this->posting->deleteVoucher($voucher);

        return response()->json(['status' => true, 'message' => 'Voucher deleted; outstanding restored']);
    }

    /**
     * Mark a PDC/PDR voucher's cheque as cleared (successfully presented).
     * POST /api/vouchers/{id}/pdc/clear  { clear_date }
     */
    public function clearPdc(Request $request, int $id)
    {
        $voucher = Voucher::find($id);
        if (! $voucher || ! in_array($voucher->voucher_type, VoucherPostingService::PDC_TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'PDC/PDR voucher not found'], 404);
        }

        $clearDate = (string) $request->input('clear_date', '');
        if ($clearDate === '' || strtotime($clearDate) === false) {
            return response()->json(['status' => false, 'message' => 'clear_date is required (Y-m-d).'], 422);
        }

        try {
            $this->posting->clearPdc($voucher, date('Y-m-d', strtotime($clearDate)));

            return response()->json([
                'status' => true,
                'message' => 'Cheque marked as cleared',
                'data' => $this->formatVoucher($voucher->fresh(['details.billAdjustments', 'ledgerEntries', 'pdcDetail'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Mark a PDC/PDR voucher's cheque as bounced: reverses its ledger effect
     * (reopens the settled bill) and keeps the record for audit.
     * POST /api/vouchers/{id}/pdc/bounce  { bounce_date }
     */
    public function bouncePdc(Request $request, int $id)
    {
        $voucher = Voucher::find($id);
        if (! $voucher || ! in_array($voucher->voucher_type, VoucherPostingService::PDC_TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'PDC/PDR voucher not found'], 404);
        }

        $bounceDate = (string) $request->input('bounce_date', '');
        if ($bounceDate === '' || strtotime($bounceDate) === false) {
            return response()->json(['status' => false, 'message' => 'bounce_date is required (Y-m-d).'], 422);
        }

        try {
            $this->posting->bouncePdc($voucher, date('Y-m-d', strtotime($bounceDate)));

            return response()->json([
                'status' => true,
                'message' => 'Cheque marked as bounced; outstanding restored',
                'data' => $this->formatVoucher($voucher->fresh(['details.billAdjustments', 'ledgerEntries', 'pdcDetail'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validate + normalise the request into the shape VoucherPostingService expects.
     * $forcedType is used on update (type cannot change). $editingId excludes the
     * voucher being edited from outstanding checks.
     */
    private function validatePayload(Request $request, ?string $forcedType = null, ?int $editingId = null): array
    {
        $errors = [];

        $type = $forcedType ?? strtoupper(trim((string) $request->input('voucher_type', '')));
        if (! in_array($type, VoucherPostingService::TYPES, true)) {
            throw ValidationException::withMessages([
                'voucher_type' => ['Invalid voucher type. Allowed: ' . implode(', ', VoucherPostingService::TYPES) . '.'],
            ]);
        }

        // Journal Vouchers have no cash/bank header and carry per-row Dr/Cr, so they
        // follow a separate validation path (ledger-only, must balance).
        if ($type === 'JV') {
            return $this->validateJournalPayload($request);
        }

        $date = (string) $request->input('voucher_date', '');
        if ($date === '' || strtotime($date) === false) {
            $errors['voucher_date'][] = 'voucher_date is required (Y-m-d).';
        }

        $cashBankId = (int) $request->input('cash_bank_account_id', 0);
        if ($cashBankId <= 0 || ! $this->posting->isValidHeaderLedger($type, $cashBankId)) {
            $required = VoucherPostingService::HEADER_ACCOUNT_TYPE[$type] ?? null;
            $errors['cash_bank_account_id'][] = $required === null
                ? "Header must be an existing general account for {$type}."
                : "Header ledger must be an existing '{$required}' general account for {$type}.";
        }

        $details = $request->input('details', []);
        if (! is_array($details) || count($details) === 0) {
            $errors['details'][] = 'At least one detail row is required.';
        }

        // All party/general categories are allowed in every voucher type. Double
        // entry stays balanced regardless (Dr/Cr is driven by isPayment), and the
        // settlement direction (settle vs refund) is handled by signed outstanding.
        $allowed = ['CUSTOMER', 'SUPPLIER', 'GENERAL'];

        $normalised = [];
        $requestedByInvoice = []; // "TYPE:id" => total requested across all rows
        foreach (is_array($details) ? $details : [] as $i => $row) {
            $category = strtoupper(trim((string) ($row['account_category'] ?? '')));
            $accountId = (int) ($row['account_id'] ?? 0);
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $label = 'details.' . $i;

            if (! in_array($category, $allowed, true)) {
                $errors[$label][] = "Category '{$category}' not allowed for {$type}. Allowed: " . implode(', ', $allowed) . '.';
                continue;
            }
            if ($accountId <= 0 || ! $this->posting->accountExists($category, $accountId)) {
                $errors[$label][] = "account_id {$accountId} does not exist for category {$category}.";
                continue;
            }
            if ($amount <= 0) {
                $errors[$label][] = 'amount must be greater than zero.';
                continue;
            }

            // Normalise bill allocations for party rows. Accepts either an
            // `allocations: [{invoice_id, amount}]` array (multi-bill) or a legacy
            // single `invoice_id` (settled in full). Unallocated remainder = advance.
            $allocations = [];
            if ($category !== 'GENERAL') {
                // A customer row can settle Sales invoices or Sales returns; a
                // supplier row can settle Purchase invoices or Purchase returns.
                $baseInvoiceType = $category === 'CUSTOMER' ? 'SALES' : 'PURCHASE';
                $allowedInvoiceTypes = $category === 'CUSTOMER'
                    ? ['SALES', 'SALES_RETURN']
                    : ['PURCHASE', 'PURCHASE_RETURN'];
                $rawAllocs = $row['allocations'] ?? null;

                if (! is_array($rawAllocs) || empty($rawAllocs)) {
                    $legacyId = isset($row['invoice_id']) && $row['invoice_id'] !== null && $row['invoice_id'] !== ''
                        ? (int) $row['invoice_id']
                        : null;
                    $rawAllocs = $legacyId !== null
                        ? [['invoice_id' => $legacyId, 'amount' => $amount]]
                        : [];
                }

                $allocSum = 0.0;
                foreach ($rawAllocs as $alloc) {
                    $invId = (int) ($alloc['invoice_id'] ?? 0);
                    $aAmt = round((float) ($alloc['amount'] ?? 0), 2);
                    if ($invId <= 0 || $aAmt <= 0) {
                        continue;
                    }
                    // Default to the party's base invoice type when the client
                    // omits invoice_type (back-compat with older app versions).
                    $invoiceType = strtoupper(trim((string) ($alloc['invoice_type'] ?? $baseInvoiceType)));
                    if (! in_array($invoiceType, $allowedInvoiceTypes, true)) {
                        $errors[$label][] = "invoice_type '{$invoiceType}' is not allowed for a {$category} row.";
                        continue;
                    }
                    if (! $this->posting->invoiceBelongsToParty($category, $accountId, $invoiceType, $invId)) {
                        $errors[$label][] = "Invoice #{$invId} does not belong to the selected {$category}.";
                        continue;
                    }
                    $allocSum += $aAmt;
                    $allocations[] = ['invoice_id' => $invId, 'invoice_type' => $invoiceType, 'amount' => $aAmt];
                    $key = $invoiceType . ':' . $invId;
                    $requestedByInvoice[$key] = ($requestedByInvoice[$key] ?? 0) + $aAmt;
                }

                if ($allocSum > $amount + 0.001) {
                    $errors[$label][] = "allocated {$allocSum} exceeds row amount {$amount}.";
                    continue;
                }
            }

            $normalised[] = [
                'account_category' => $category,
                'account_id' => $accountId,
                'amount' => $amount,
                'narration' => $row['narration'] ?? null,
                'allocations' => $allocations,
            ];
        }

        // Per-invoice cap, aggregated across all rows of this voucher. Settling
        // vouchers are capped by the open balance; refund vouchers (reverse
        // direction) are capped by the amount already settled.
        foreach ($requestedByInvoice as $key => $requested) {
            [$invoiceType, $invId] = explode(':', $key);
            $invId = (int) $invId;
            $available = $this->availableForAllocation($type, $invoiceType, $invId, $editingId);
            if ($available !== null && $requested > $available + 0.001) {
                $errors['details'][] = "Allocated {$requested} exceeds available {$available} on invoice #{$invId}.";
            }
        }

        // PDC/PDR carry the physical cheque's identifying details.
        $chequeNo = null;
        $chequeDate = null;
        if (in_array($type, VoucherPostingService::PDC_TYPES, true)) {
            $chequeNo = trim((string) $request->input('cheque_no', ''));
            if ($chequeNo === '') {
                $errors['cheque_no'][] = 'cheque_no is required.';
            }
            $chequeDateRaw = (string) $request->input('cheque_date', '');
            if ($chequeDateRaw === '' || strtotime($chequeDateRaw) === false) {
                $errors['cheque_date'][] = 'cheque_date is required (Y-m-d).';
            } else {
                $chequeDate = date('Y-m-d', strtotime($chequeDateRaw));
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'voucher_type' => $type,
            'voucher_date' => date('Y-m-d', strtotime($date)),
            'cash_bank_account_id' => $cashBankId,
            'narration' => $request->input('narration'),
            'created_by' => $request->input('created_by'),
            'details' => $normalised,
            'cheque_no' => $chequeNo,
            'cheque_date' => $chequeDate,
            'bank_name' => $request->input('bank_name'),
        ];
    }

    /**
     * Validate + normalise a Journal Voucher (JV) payload. A JV has no cash/bank
     * header; each row is independently a Debit OR a Credit (never both, never
     * neither), it needs at least two rows, and total Dr must equal total Cr.
     * Ledger-only: no bill allocations / outstanding caps. Mirrors the rules in
     * the JV screen's _buildLines() so client and server agree.
     */
    private function validateJournalPayload(Request $request): array
    {
        $errors = [];

        $date = (string) $request->input('voucher_date', '');
        if ($date === '' || strtotime($date) === false) {
            $errors['voucher_date'][] = 'voucher_date is required (Y-m-d).';
        }

        $details = $request->input('details', []);
        if (! is_array($details) || count($details) === 0) {
            $errors['details'][] = 'At least one detail row is required.';
        }

        $allowed = ['CUSTOMER', 'SUPPLIER', 'GENERAL'];

        $normalised = [];
        $totalDr = 0.0;
        $totalCr = 0.0;
        foreach (is_array($details) ? $details : [] as $i => $row) {
            $category = strtoupper(trim((string) ($row['account_category'] ?? '')));
            $accountId = (int) ($row['account_id'] ?? 0);
            $dr = round((float) ($row['dr_amount'] ?? 0), 2);
            $cr = round((float) ($row['cr_amount'] ?? 0), 2);
            $label = 'details.' . $i;

            if (! in_array($category, $allowed, true)) {
                $errors[$label][] = "Category '{$category}' not allowed. Allowed: " . implode(', ', $allowed) . '.';
                continue;
            }
            if ($accountId <= 0 || ! $this->posting->accountExists($category, $accountId)) {
                $errors[$label][] = "account_id {$accountId} does not exist for category {$category}.";
                continue;
            }
            if ($dr < 0 || $cr < 0) {
                $errors[$label][] = 'Debit/Credit cannot be negative.';
                continue;
            }
            if ($dr > 0 && $cr > 0) {
                $errors[$label][] = 'Enter either Debit or Credit, not both.';
                continue;
            }
            if ($dr <= 0 && $cr <= 0) {
                $errors[$label][] = 'Enter a Debit or Credit amount.';
                continue;
            }

            $totalDr += $dr;
            $totalCr += $cr;
            $normalised[] = [
                'account_category' => $category,
                'account_id' => $accountId,
                'dr_amount' => $dr,
                'cr_amount' => $cr,
                'narration' => $row['narration'] ?? null,
            ];
        }

        if (count($normalised) < 2) {
            $errors['details'][] = 'A journal needs at least two lines.';
        } elseif (round($totalDr, 2) !== round($totalCr, 2)) {
            $errors['details'][] = 'Total Debit must equal Total Credit.';
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'voucher_type' => 'JV',
            'voucher_date' => date('Y-m-d', strtotime($date)),
            'cash_bank_account_id' => null,
            'narration' => $request->input('narration'),
            'created_by' => $request->input('created_by'),
            'details' => $normalised,
        ];
    }

    /**
     * How much of an invoice this voucher may still allocate. In the *settling*
     * direction the cap is the open balance; in the *refund* (reverse) direction
     * it is the amount already settled (you cannot refund more than was settled).
     *
     * On edit, validation runs before the voucher's own adjustments are reversed,
     * so this voucher's own signed contribution is added back to reflect the
     * post-reversal state.
     */
    private function availableForAllocation(string $type, string $invoiceType, int $invoiceId, ?int $editingId): ?float
    {
        $outstanding = $this->posting->invoiceOutstanding($invoiceType, $invoiceId);
        $total = $this->posting->invoiceTotal($invoiceType, $invoiceId);
        if ($outstanding === null || $total === null) {
            return null;
        }

        $settling = $this->posting->isSettlingDirection($type, $invoiceType);

        $ownSigned = 0.0;
        if ($editingId !== null) {
            $ownAbs = (float) DB::table('bill_adjustments as ba')
                ->join('voucher_details as vd', 'vd.id', '=', 'ba.voucher_detail_id')
                ->where('vd.voucher_id', $editingId)
                ->where('ba.invoice_type', $invoiceType)
                ->where('ba.invoice_id', $invoiceId)
                ->where('ba.adjustment_type', 'AGAINST_REF')
                ->sum('ba.adjusted_amount');
            $ownSigned = $settling ? $ownAbs : -$ownAbs;
        }

        // settled' = (total - outstanding) - ownSigned ; balance' = outstanding + ownSigned
        $balance = round($outstanding + $ownSigned, 2);
        $settled = round(($total - $outstanding) - $ownSigned, 2);

        return $settling ? $balance : $settled;
    }

    // ── Formatting ───────────────────────────────────────────────────────────

    private function formatVoucher(Voucher $voucher): array
    {
        $cashBankName = $this->generalAccountNames([$voucher->cash_bank_account_id])[$voucher->cash_bank_account_id] ?? null;

        $details = $voucher->details->map(function ($d) {
            $adjustments = $d->billAdjustments;
            $allocations = $adjustments
                ->where('adjustment_type', 'AGAINST_REF')
                ->map(fn ($ba) => [
                    'invoice_type' => $ba->invoice_type,
                    'invoice_id' => $ba->invoice_id,
                    'amount' => (float) $ba->adjusted_amount,
                ])->values();
            $advance = $adjustments->firstWhere('adjustment_type', 'ON_ACCOUNT');

            return [
                'id' => $d->id,
                'account_category' => $d->account_category,
                'account_id' => $d->account_id,
                'account_name' => $this->resolveName($d->account_category, $d->account_id),
                'amount' => (float) $d->amount,
                'narration' => $d->narration,
                'allocations' => $allocations,
                'advance_amount' => $advance ? (float) $advance->adjusted_amount : null,
            ];
        })->values();

        $pdc = $voucher->relationLoaded('pdcDetail') ? $voucher->pdcDetail : $voucher->pdcDetail()->first();

        return [
            'id' => $voucher->id,
            'voucher_type' => $voucher->voucher_type,
            'voucher_no' => $voucher->voucher_no,
            'fy' => $voucher->fy,
            'voucher_date' => $this->dateStr($voucher->voucher_date),
            'cash_bank_account_id' => $voucher->cash_bank_account_id,
            'cash_bank_account_name' => $cashBankName,
            'total_amount' => (float) $voucher->total_amount,
            'narration' => $voucher->narration,
            'status' => $voucher->status,
            'details' => $details,
            'ledger_entries' => $voucher->ledgerEntries->map(fn ($l) => [
                'ledger_source' => $l->ledger_source,
                'ledger_id' => $l->ledger_id,
                'dr_amount' => (float) $l->dr_amount,
                'cr_amount' => (float) $l->cr_amount,
                'entry_date' => $this->dateStr($l->entry_date),
            ])->values(),
            'cheque_no' => $pdc?->cheque_no,
            'cheque_date' => $pdc ? $this->dateStr($pdc->cheque_date) : null,
            'bank_name' => $pdc?->bank_name,
            'pdc_status' => $pdc?->status,
            'pdc_cleared_date' => $pdc ? $this->dateStr($pdc->cleared_date) : null,
            'pdc_bounced_date' => $pdc ? $this->dateStr($pdc->bounced_date) : null,
        ];
    }

    private function resolveName(string $category, int $accountId): ?string
    {
        return match ($category) {
            'CUSTOMER' => DB::table('user')->where('userid', $accountId)->value('name'),
            'SUPPLIER' => DB::table('suppliers')->where('id', $accountId)->value('supplier_name'),
            'GENERAL' => GeneralAccount::whereKey($accountId)->value('account_name'),
            default => null,
        };
    }

    /** @return array<int,string> id => account_name */
    private function generalAccountNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        return GeneralAccount::whereIn('id', $ids)
            ->pluck('account_name', 'id')
            ->all();
    }

    private function dateStr($date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
    }
}
