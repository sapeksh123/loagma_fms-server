<?php

namespace App\Http\Controllers;

use App\Services\OutstandingService;
use Illuminate\Http\Request;

class OutstandingController extends Controller
{
    public function __construct(private OutstandingService $outstanding)
    {
    }

    /**
     * Bill-wise open invoices for a party, used by the voucher-entry bill picker.
     * GET /api/outstanding/bills?party_type=CUSTOMER|SUPPLIER&party_id={id}&only_open=1
     */
    public function bills(Request $request)
    {
        $partyType = strtoupper(trim((string) $request->get('party_type', '')));
        $partyId = (int) $request->get('party_id', 0);
        $onlyOpen = $request->boolean('only_open', true);

        if ($partyId <= 0) {
            return response()->json(['status' => false, 'message' => 'party_id is required'], 422);
        }

        $data = match ($partyType) {
            'CUSTOMER' => $this->outstanding->customerBills($partyId, $onlyOpen),
            'SUPPLIER' => $this->outstanding->supplierBills($partyId, $onlyOpen),
            default => null,
        };

        if ($data === null) {
            return response()->json(['status' => false, 'message' => 'party_type must be CUSTOMER or SUPPLIER'], 422);
        }

        return response()->json(['status' => true, 'data' => $data]);
    }
}
