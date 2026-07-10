<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Builds only the tables the accounting engine touches against a fresh in-memory
 * SQLite DB (the repo's full migration set can't run on SQLite due to legacy
 * tables without create-migrations, so RefreshDatabase is not used).
 */
class VoucherAccountingTest extends TestCase
{
    private int $cashAccountId;
    private int $bankAccountId;
    private int $expenseAccountId;
    private int $incomeAccountId;
    private int $customerId;
    private int $supplierId;
    private int $orderId;
    private int $orderId2;
    private int $purchaseId;

    protected function setUp(): void
    {
        parent::setUp();

        // Dependency tables (general_account + suppliers) and the legacy tables that
        // have no migration in this repo (user, orders, purchase_vouchers).
        if (! Schema::hasTable('general_account')) {
            Schema::create('general_account', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('account_no', 100)->unique();
                $t->string('account_name', 255);
                $t->string('account_type', 100);
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('supplier_code', 50)->unique();
                $t->string('supplier_name', 255);
                $t->string('contact_person', 255)->nullable();
                $t->string('address_line1', 255)->nullable();
                $t->string('city', 120)->nullable();
                $t->string('phone', 30);
            });
        }
        if (! Schema::hasTable('user')) {
            Schema::create('user', function (Blueprint $t) {
                $t->bigIncrements('userid');
                $t->string('name')->nullable();
                $t->string('shop_name')->nullable();
                $t->string('shop_address')->nullable();
                $t->string('address')->nullable();
                $t->string('contactno', 30)->nullable();
            });
        }
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $t) {
                $t->bigIncrements('order_id');
                $t->unsignedBigInteger('buyer_userid');
                $t->string('bill_no', 100)->nullable();
                $t->string('invoice_number', 100)->nullable();
                $t->string('Bill_Narration', 255)->nullable();
                $t->date('Bill_Dt')->nullable();
                $t->decimal('order_total', 12, 2)->default(0);
                // Sales-return markers carried on the order row (legacy CRM columns).
                $t->string('Sales_Return_VoucherNo', 100)->nullable();
                $t->date('Sales_Return_Dt')->nullable();
            });
        }
        if (! Schema::hasTable('orders_item')) {
            Schema::create('orders_item', function (Blueprint $t) {
                $t->bigIncrements('item_id');
                $t->unsignedBigInteger('order_id');
                $t->integer('qty_returned')->default(0);
                $t->decimal('item_price', 12, 2)->default(0);
            });
        }
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('supplier_id');
                $t->unsignedBigInteger('source_purchase_voucher_id')->nullable();
                $t->string('doc_no', 80)->nullable();
                $t->date('doc_date')->nullable();
                $t->decimal('net_total', 14, 2)->default(0);
                $t->string('status', 20)->default('POSTED');
            });
        }
        if (! Schema::hasTable('purchase_vouchers')) {
            Schema::create('purchase_vouchers', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('doc_no', 80)->nullable();
                $t->unsignedBigInteger('purchase_order_id')->nullable();
                $t->date('doc_date')->nullable();
                $t->string('bill_no', 100)->nullable();
                $t->string('narration', 255)->nullable();
                $t->unsignedBigInteger('supplier_id');
                $t->decimal('net_total', 14, 2)->default(0);
                $t->string('status', 20)->default('POSTED');
            });
        }

        // Load the accounting module's own 5 migrations (real schema).
        foreach (glob(database_path('migrations/2026_06_13_*.php')) as $file) {
            (require $file)->up();
        }

        $this->cashAccountId = DB::table('general_account')->insertGetId([
            'account_no' => '1001', 'account_name' => 'Cash Account', 'account_type' => 'Cash', 'created_at' => now(),
        ]);
        $this->bankAccountId = DB::table('general_account')->insertGetId([
            'account_no' => '1002', 'account_name' => 'Bank Account', 'account_type' => 'Bank', 'created_at' => now(),
        ]);
        $this->expenseAccountId = DB::table('general_account')->insertGetId([
            'account_no' => '5001', 'account_name' => 'Electricity Expense', 'account_type' => 'Expenses', 'created_at' => now(),
        ]);
        $this->incomeAccountId = DB::table('general_account')->insertGetId([
            'account_no' => '4001', 'account_name' => 'Commission Income', 'account_type' => 'Income', 'created_at' => now(),
        ]);

        $this->customerId = DB::table('user')->insertGetId(['name' => 'Khaled']);
        $this->orderId = DB::table('orders')->insertGetId([
            'buyer_userid' => $this->customerId, 'bill_no' => 'INV/25-26/004', 'Bill_Dt' => '2025-05-20', 'order_total' => 10000,
        ]);
        $this->orderId2 = DB::table('orders')->insertGetId([
            'buyer_userid' => $this->customerId, 'bill_no' => 'INV/25-26/005', 'Bill_Dt' => '2025-05-22', 'order_total' => 4000,
        ]);

        $this->supplierId = DB::table('suppliers')->insertGetId([
            'supplier_code' => 'SUP001', 'supplier_name' => 'Supplier A', 'phone' => '9999999999',
        ]);
        $this->purchaseId = DB::table('purchase_vouchers')->insertGetId([
            'doc_no' => 'PB001', 'purchase_order_id' => 7788, 'doc_date' => '2025-05-18', 'bill_no' => 'PB001',
            'supplier_id' => $this->supplierId, 'net_total' => 5000, 'status' => 'POSTED',
        ]);
    }

    public function test_cash_receipt_against_customer_invoice_posts_balanced_and_reduces_outstanding(): void
    {
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'narration' => 'Receipt',
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 3000,
                'invoice_id' => $this->orderId,
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertSame('CR/25-26/1', $res->json('data.voucher_no'));

        // Ledger balanced.
        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals(3000, $dr);
        $this->assertEquals(3000, $cr);

        // Outstanding reduced 10000 -> 7000.
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $bills->assertOk();
        $this->assertEquals(7000, $bills->json('data.0.balance'));
    }

    public function test_multi_bill_allocation_with_remainder_advance(): void
    {
        // One customer row of 5000 split across two invoices (3000 + 1500),
        // leaving 500 as an on-account advance.
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-23',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 5000,
                'allocations' => [
                    ['invoice_id' => $this->orderId, 'amount' => 3000],
                    ['invoice_id' => $this->orderId2, 'amount' => 1500],
                ],
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertEquals(2, DB::table('bill_adjustments')->where('adjustment_type', 'AGAINST_REF')->count());
        $this->assertDatabaseHas('party_advances', ['party_id' => $this->customerId, 'amount' => 500]);

        // Ledger still balanced at the full row amount.
        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals(5000, $dr);
        $this->assertEquals(5000, $cr);

        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $byId = collect($bills->json('data'))->keyBy('invoice_id');
        $this->assertEquals(7000, $byId[$this->orderId]['balance']);
        $this->assertEquals(2500, $byId[$this->orderId2]['balance']);
    }

    public function test_allocation_exceeding_invoice_outstanding_is_rejected(): void
    {
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-23',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 5000,
                'allocations' => [['invoice_id' => $this->orderId2, 'amount' => 5000]], // bill is only 4000
            ]],
        ]);

        $res->assertStatus(422);
    }

    public function test_on_account_receipt_creates_advance(): void
    {
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 5000,
                // no invoice_id => on account
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('party_advances', [
            'party_type' => 'CUSTOMER', 'party_id' => $this->customerId, 'amount' => 5000, 'remaining_amount' => 5000,
        ]);
    }

    public function test_payment_exceeding_outstanding_is_rejected(): void
    {
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'SUPPLIER',
                'account_id' => $this->supplierId,
                'amount' => 6000, // bill is only 5000
                'invoice_id' => $this->purchaseId,
            ]],
        ]);

        $res->assertStatus(422);
    }

    public function test_voucher_numbering_is_per_type_and_fy(): void
    {
        foreach (['CP', 'CP', 'CR'] as $type) {
            $this->postJson('/api/vouchers', [
                'voucher_type' => $type,
                'voucher_date' => '2025-06-01',
                'cash_bank_account_id' => $this->cashAccountId,
                'details' => [[
                    'account_category' => $type === 'CP' ? 'GENERAL' : 'GENERAL',
                    'account_id' => $type === 'CP' ? $this->expenseAccountId : $this->incomeAccountId,
                    'amount' => 100,
                ]],
            ])->assertStatus(201);
        }

        $this->assertEquals(2, DB::table('vouchers')->where('voucher_type', 'CP')->count());
        $this->assertEquals('CP/25-26/2', DB::table('vouchers')->where('voucher_type', 'CP')->orderByDesc('seq')->value('voucher_no'));
        $this->assertEquals('CR/25-26/1', DB::table('vouchers')->where('voucher_type', 'CR')->value('voucher_no'));
    }

    public function test_update_reverses_and_reposts(): void
    {
        $create = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId,
            ]],
        ])->assertStatus(201);

        $id = $create->json('data.id');

        $this->putJson("/api/vouchers/{$id}", [
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 4000, 'invoice_id' => $this->orderId,
            ]],
        ])->assertOk();

        // Only the new adjustment should remain; outstanding = 10000 - 4000 = 6000.
        $this->assertEquals(1, DB::table('bill_adjustments')->count());
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $this->assertEquals(6000, $bills->json('data.0.balance'));
    }

    public function test_delete_restores_outstanding(): void
    {
        $create = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId,
            ]],
        ])->assertStatus(201);

        $this->deleteJson('/api/vouchers/' . $create->json('data.id'))->assertOk();

        $this->assertEquals(0, DB::table('bill_adjustments')->count());
        $this->assertEquals(0, DB::table('ledger_entries')->count());
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $this->assertEquals(10000, $bills->json('data.0.balance'));
    }

    public function test_cash_payment_general_expense_is_balanced(): void
    {
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [
                ['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 2000, 'invoice_id' => $this->purchaseId],
                ['account_category' => 'GENERAL', 'account_id' => $this->expenseAccountId, 'amount' => 1500],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertEquals(3500, $res->json('data.total_amount'));
        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals($dr, $cr);
        $this->assertEquals(3500, $cr);
    }

    public function test_rejects_wrong_header_account_type(): void
    {
        // CP requires a Cash header; passing a Bank account must fail.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->bankAccountId,
            'details' => [['account_category' => 'GENERAL', 'account_id' => $this->expenseAccountId, 'amount' => 100]],
        ])->assertStatus(422);
    }

    public function test_allows_customer_row_on_payment_voucher(): void
    {
        // All categories are now allowed in every voucher type. A customer row in
        // a payment with no allocation posts balanced as an on-account refund.
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 100]],
        ]);

        $res->assertStatus(201);
        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals($dr, $cr);
    }

    public function test_customer_refund_on_payment_increases_outstanding(): void
    {
        // Receive 3000 against the 10000 sales bill -> outstanding 7000.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);

        // Refund 2000 of it via a Cash Payment to the customer (reverse direction).
        $refund = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-24',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 2000,
                'allocations' => [['invoice_id' => $this->orderId, 'amount' => 2000]]]],
        ]);
        $refund->assertStatus(201);

        // Net settled = 3000 - 2000 = 1000, so outstanding climbs back to 9000.
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $byId = collect($bills->json('data'))->keyBy('invoice_id');
        $this->assertEquals(9000, $byId[$this->orderId]['balance']);
    }

    public function test_refund_cannot_exceed_settled_amount(): void
    {
        // Only 3000 has been received against the bill...
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-21',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);

        // ...so a 5000 refund against it must be rejected.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-24',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 5000,
                'allocations' => [['invoice_id' => $this->orderId, 'amount' => 5000]]]],
        ])->assertStatus(422);
    }

    public function test_supplier_refund_on_receipt_increases_outstanding(): void
    {
        // Pay 2000 against the 5000 purchase bill -> outstanding 3000.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 2000, 'invoice_id' => $this->purchaseId]],
        ])->assertStatus(201);

        // Supplier refunds 1000 via a Cash Receipt (reverse direction).
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-25',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 1000,
                'allocations' => [['invoice_id' => $this->purchaseId, 'amount' => 1000]]]],
        ])->assertStatus(201);

        // Net settled = 2000 - 1000 = 1000, so outstanding climbs back to 4000.
        $bills = $this->getJson("/api/outstanding/bills?party_type=SUPPLIER&party_id={$this->supplierId}");
        $this->assertEquals(4000, $bills->json('data.0.balance'));
        // Supplier bills expose the purchase_order_id as order_no.
        $this->assertSame('7788', $bills->json('data.0.order_no'));
    }

    public function test_outstanding_detail_groups_pending_bills_only(): void
    {
        // Partly receive against bill #1 (10000 -> 7000), fully settle bill #2 (4000).
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR', 'voucher_date' => '2025-05-21', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR', 'voucher_date' => '2025-05-23', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 4000, 'invoice_id' => $this->orderId2]],
        ])->assertStatus(201);

        $res = $this->getJson('/api/reports/outstanding-detail?party_type=CUSTOMER&as_on=2025-12-31');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertEquals(1, $data['party_count']);
        $this->assertEquals(1, $data['bill_count']); // only the pending bill #1
        $this->assertEquals(7000, $data['report_total_balance']);

        $bill = $data['groups'][0]['bills'][0];
        $this->assertEquals($this->orderId, $bill['invoice_id']);
        $this->assertEquals(10000, $bill['amount']);
        $this->assertEquals(3000, $bill['adjustments']);
        $this->assertEquals(7000, $bill['balance']);
        $this->assertGreaterThan(0, $bill['overdue_days']);
    }

    public function test_outstanding_detail_respects_as_on_cutoff(): void
    {
        // A receipt dated 2025-05-21.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR', 'voucher_date' => '2025-05-21', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);

        // As on 2025-05-20: the receipt and bill #2 are after this date -> only bill #1, fully open.
        $before = $this->getJson('/api/reports/outstanding-detail?party_type=CUSTOMER&as_on=2025-05-20');
        $this->assertEquals(1, $before->json('data.bill_count'));
        $this->assertEquals(10000, $before->json('data.report_total_balance'));

        // As on 2025-12-31: receipt counted (bill #1 = 7000) + bill #2 fully open (4000) = 11000.
        $after = $this->getJson('/api/reports/outstanding-detail?party_type=CUSTOMER&as_on=2025-12-31');
        $this->assertEquals(2, $after->json('data.bill_count'));
        $this->assertEquals(11000, $after->json('data.report_total_balance'));
    }

    public function test_outstanding_detail_account_ids_filter(): void
    {
        $match = $this->getJson("/api/reports/outstanding-detail?party_type=CUSTOMER&as_on=2025-12-31&account_ids={$this->customerId}");
        $this->assertEquals(1, $match->json('data.party_count'));

        $none = $this->getJson('/api/reports/outstanding-detail?party_type=CUSTOMER&as_on=2025-12-31&account_ids=999999');
        $this->assertEquals(0, $none->json('data.party_count'));
        $this->assertEquals(0, $none->json('data.bill_count'));
    }

    public function test_outstanding_detail_supplier(): void
    {
        // Pay 2000 against the 5000 purchase bill -> balance 3000.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP', 'voucher_date' => '2025-05-22', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 2000, 'invoice_id' => $this->purchaseId]],
        ])->assertStatus(201);

        $res = $this->getJson('/api/reports/outstanding-detail?party_type=SUPPLIER&as_on=2025-12-31');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertEquals(1, $data['party_count']);
        $bill = $data['groups'][0]['bills'][0];
        $this->assertEquals(5000, $bill['amount']);
        $this->assertEquals(2000, $bill['adjustments']);
        $this->assertEquals(3000, $bill['balance']);
        $this->assertEquals('7788', $bill['order_no']);
    }

    public function test_ledger_detail_customer_running_balance(): void
    {
        // Receive 3000 against bill #1 (2025-05-21).
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR', 'voucher_date' => '2025-05-21', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);

        $res = $this->getJson('/api/reports/ledger-detail?type=CUSTOMER&from=2025-01-01&to=2025-12-31');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertEquals(1, $data['account_count']);
        $this->assertEquals(3, $data['row_count']); // 2 invoices + 1 receipt (opening row not counted)

        $g = $data['groups'][0];
        $this->assertEquals(14000, $g['total_debit']); // 10000 + 4000 invoices
        $this->assertEquals(3000, $g['total_credit']);
        $this->assertEquals(11000, $g['closing_balance']);
        $this->assertEquals('Dr', $g['closing_dr_cr']);
        $this->assertSame('Opening Balance', $g['rows'][0]['particulars']);
        $this->assertEquals(0, $g['rows'][0]['balance']); // nothing before 2025-01-01
    }

    public function test_ledger_detail_opening_balance_carries_forward(): void
    {
        // Invoice #1 (2025-05-20) + receipt (2025-05-21) both fall BEFORE the From date.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR', 'voucher_date' => '2025-05-21', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 3000, 'invoice_id' => $this->orderId]],
        ])->assertStatus(201);

        // From 2025-05-22: opening = 10000 - 3000 = 7000 Dr; period has only invoice #2 (4000).
        $res = $this->getJson('/api/reports/ledger-detail?type=CUSTOMER&from=2025-05-22&to=2025-12-31');
        $res->assertOk();
        $g = $res->json('data.groups.0');
        $this->assertEquals(7000, $g['opening_balance']);
        $this->assertEquals(4000, $g['total_debit']);
        $this->assertEquals(0, $g['total_credit']);
        $this->assertEquals(11000, $g['closing_balance']);
        $this->assertEquals('Dr', $g['closing_dr_cr']);
        // Opening row + the single period row.
        $this->assertCount(2, $g['rows']);
        $this->assertEquals(7000, $g['rows'][0]['balance']);
    }

    public function test_ledger_detail_account_ids_filter(): void
    {
        $none = $this->getJson('/api/reports/ledger-detail?type=CUSTOMER&from=2025-01-01&to=2025-12-31&account_ids=999999');
        $this->assertEquals(0, $none->json('data.account_count'));

        $match = $this->getJson("/api/reports/ledger-detail?type=CUSTOMER&from=2025-01-01&to=2025-12-31&account_ids={$this->customerId}");
        $this->assertEquals(1, $match->json('data.account_count'));
    }

    public function test_ledger_detail_supplier_is_payable(): void
    {
        // Pay 2000 against the 5000 purchase bill.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP', 'voucher_date' => '2025-05-22', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 2000, 'invoice_id' => $this->purchaseId]],
        ])->assertStatus(201);

        $res = $this->getJson('/api/reports/ledger-detail?type=SUPPLIER&from=2025-01-01&to=2025-12-31');
        $res->assertOk();
        $g = $res->json('data.groups.0');
        $this->assertEquals(2000, $g['total_debit']);
        $this->assertEquals(5000, $g['total_credit']);
        $this->assertEquals(3000, $g['closing_balance']);
        $this->assertEquals('Cr', $g['closing_dr_cr']); // payable
    }

    public function test_credit_note_credits_customer_with_general_header(): void
    {
        // A non-Cash general account as the header (textbook sales-return contra).
        $salesReturn = DB::table('general_account')->insertGetId([
            'account_no' => '4002', 'account_name' => 'Sales Return', 'account_type' => 'Trading Exp', 'created_at' => now(),
        ]);

        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CN',
            'voucher_date' => '2025-05-25',
            'cash_bank_account_id' => $salesReturn, // any general account allowed for CN/DN
            'details' => [['account_category' => 'CUSTOMER', 'account_id' => $this->customerId, 'amount' => 2000, 'invoice_id' => $this->orderId]],
        ]);

        $res->assertStatus(201);
        $this->assertSame('CN/25-26/1', $res->json('data.voucher_no'));

        // Balanced: Dr Sales Return (header) / Cr Customer (detail).
        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals(2000, $dr);
        $this->assertEquals(2000, $cr);

        // Customer outstanding reduced 10000 -> 8000 (CN settles SALES like a receipt).
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $this->assertEquals(8000, $bills->json('data.0.balance'));
    }

    public function test_debit_note_debits_supplier_with_general_header(): void
    {
        $purchaseReturn = DB::table('general_account')->insertGetId([
            'account_no' => '5002', 'account_name' => 'Purchase Return', 'account_type' => 'Trading Income', 'created_at' => now(),
        ]);

        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'DN',
            'voucher_date' => '2025-05-25',
            'cash_bank_account_id' => $purchaseReturn,
            'details' => [['account_category' => 'SUPPLIER', 'account_id' => $this->supplierId, 'amount' => 1500, 'invoice_id' => $this->purchaseId]],
        ]);

        $res->assertStatus(201);
        $this->assertSame('DN/25-26/1', $res->json('data.voucher_no'));

        $dr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('dr_amount');
        $cr = DB::table('ledger_entries')->where('voucher_id', $res->json('data.id'))->sum('cr_amount');
        $this->assertEquals($dr, $cr);
        $this->assertEquals(1500, $dr);

        // Supplier outstanding reduced 5000 -> 3500 (DN settles PURCHASE like a payment).
        $bills = $this->getJson("/api/outstanding/bills?party_type=SUPPLIER&party_id={$this->supplierId}");
        $this->assertEquals(3500, $bills->json('data.0.balance'));
    }

    public function test_cash_payment_still_rejects_non_cash_header(): void
    {
        // CP/BP/CR/BR keep their strict Cash/Bank header rule.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-22',
            'cash_bank_account_id' => $this->expenseAccountId, // an Expenses account, not Cash
            'details' => [['account_category' => 'GENERAL', 'account_id' => $this->incomeAccountId, 'amount' => 100]],
        ])->assertStatus(422);
    }

    public function test_ledger_detail_general_account(): void
    {
        // CP that debits a general expense account.
        $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP', 'voucher_date' => '2025-05-22', 'cash_bank_account_id' => $this->cashAccountId,
            'details' => [['account_category' => 'GENERAL', 'account_id' => $this->expenseAccountId, 'amount' => 1500]],
        ])->assertStatus(201);

        $res = $this->getJson("/api/reports/ledger-detail?type=GENERAL&from=2025-01-01&to=2025-12-31&account_ids={$this->expenseAccountId}");
        $res->assertOk();
        $g = $res->json('data.groups.0');
        $this->assertEquals(1500, $g['total_debit']);
        $this->assertEquals(1500, $g['closing_balance']);
        $this->assertEquals('Dr', $g['closing_dr_cr']);
    }

    public function test_sales_return_shows_in_customer_bills_and_payment_settles_it(): void
    {
        // Mark orderId as having a sales return worth 1500 (3 units * 500).
        DB::table('orders')->where('order_id', $this->orderId)->update([
            'Sales_Return_VoucherNo' => 'SR/25-26/001',
            'Sales_Return_Dt' => '2025-05-26',
        ]);
        DB::table('orders_item')->insert([
            ['order_id' => $this->orderId, 'qty_returned' => 3, 'item_price' => 500],
            ['order_id' => $this->orderId, 'qty_returned' => 0, 'item_price' => 999], // not returned, ignored
        ]);

        // The return appears as a SALES_RETURN bill (separate from the SALES invoice).
        $bills = $this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}");
        $bills->assertOk();
        $ret = collect($bills->json('data'))->firstWhere('invoice_type', 'SALES_RETURN');
        $this->assertNotNull($ret, 'sales return should be listed');
        $this->assertEquals($this->orderId, $ret['invoice_id']);
        $this->assertEquals(1500, $ret['total']);
        $this->assertEquals(1500, $ret['balance']);

        // A Cash Payment to the customer, allocated to the return, settles it.
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-27',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 1000,
                'allocations' => [
                    ['invoice_id' => $this->orderId, 'invoice_type' => 'SALES_RETURN', 'amount' => 1000],
                ],
            ]],
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('bill_adjustments', [
            'invoice_type' => 'SALES_RETURN', 'invoice_id' => $this->orderId, 'adjusted_amount' => 1000,
        ]);

        // Return balance 1500 -> 500; the SALES invoice itself stays at full 10000.
        $bills2 = collect($this->getJson("/api/outstanding/bills?party_type=CUSTOMER&party_id={$this->customerId}")->json('data'));
        $ret2 = $bills2->firstWhere('invoice_type', 'SALES_RETURN');
        $this->assertEquals(500, $ret2['balance']);
        $sales = $bills2->first(fn ($b) => $b['invoice_type'] === 'SALES' && $b['invoice_id'] === $this->orderId);
        $this->assertEquals(10000, $sales['balance']);
    }

    public function test_purchase_return_shows_in_supplier_bills_and_receipt_settles_it(): void
    {
        $prId = DB::table('purchase_returns')->insertGetId([
            'supplier_id' => $this->supplierId,
            'source_purchase_voucher_id' => $this->purchaseId,
            'doc_no' => 'PR/25-26/001',
            'doc_date' => '2025-05-26',
            'net_total' => 1200,
            'status' => 'POSTED',
        ]);

        $bills = collect($this->getJson("/api/outstanding/bills?party_type=SUPPLIER&party_id={$this->supplierId}")->json('data'));
        $ret = $bills->firstWhere('invoice_type', 'PURCHASE_RETURN');
        $this->assertNotNull($ret, 'purchase return should be listed');
        $this->assertEquals($prId, $ret['invoice_id']);
        $this->assertEquals(1200, $ret['balance']);

        // A Cash Receipt from the supplier (CR) settles the purchase return.
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CR',
            'voucher_date' => '2025-05-27',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'SUPPLIER',
                'account_id' => $this->supplierId,
                'amount' => 800,
                'allocations' => [
                    ['invoice_id' => $prId, 'invoice_type' => 'PURCHASE_RETURN', 'amount' => 800],
                ],
            ]],
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('bill_adjustments', [
            'invoice_type' => 'PURCHASE_RETURN', 'invoice_id' => $prId, 'adjusted_amount' => 800,
        ]);

        $ret2 = collect($this->getJson("/api/outstanding/bills?party_type=SUPPLIER&party_id={$this->supplierId}")->json('data'))
            ->firstWhere('invoice_type', 'PURCHASE_RETURN');
        $this->assertEquals(400, $ret2['balance']);
    }

    public function test_allocation_invoice_type_must_match_party_category(): void
    {
        // A CUSTOMER row may not allocate a PURCHASE_RETURN document.
        $res = $this->postJson('/api/vouchers', [
            'voucher_type' => 'CP',
            'voucher_date' => '2025-05-27',
            'cash_bank_account_id' => $this->cashAccountId,
            'details' => [[
                'account_category' => 'CUSTOMER',
                'account_id' => $this->customerId,
                'amount' => 500,
                'allocations' => [['invoice_id' => 1, 'invoice_type' => 'PURCHASE_RETURN', 'amount' => 500]],
            ]],
        ]);
        $res->assertStatus(422);
    }
}
