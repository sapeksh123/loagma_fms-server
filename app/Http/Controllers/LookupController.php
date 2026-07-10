<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use App\Models\Department;
use App\Models\PincodeMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LookupController extends Controller
{
    public function businessTypes()
    {
        $items = BusinessType::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])
            ->values();

        return response()->json(['status' => true, 'data' => $items]);
    }

    public function departments()
    {
        $items = Department::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])
            ->values();

        return response()->json(['status' => true, 'data' => $items]);
    }

    public function pincode(string $pincode)
    {
        $normalized = preg_replace('/\s+/', '', trim($pincode));

        if ($normalized === '' || !preg_match('/^\d{6}$/', $normalized)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid pincode',
            ], 422);
        }

        $local = PincodeMaster::where('pincode', $normalized)->where('is_active', true)->first();
        if ($local) {
            return response()->json([
                'status' => true,
                'data' => [
                    'pincode' => $local->pincode,
                    'city' => $local->city,
                    'state' => $local->state,
                    'country' => $local->country,
                    'source' => 'local',
                ],
            ]);
        }

        try {
            $response = Http::timeout(10)->get('https://api.postalpincode.in/pincode/' . $normalized);
            if ($response->successful()) {
                $payload = $response->json();
                $entry = $payload[0] ?? null;
                $postOffices = $entry['PostOffice'] ?? [];

                if (($entry['Status'] ?? '') === 'Success' && !empty($postOffices)) {
                    $office = $postOffices[0];
                    return response()->json([
                        'status' => true,
                        'data' => [
                            'pincode' => $normalized,
                            'city' => $office['District'] ?? $office['Block'] ?? $office['Region'] ?? '',
                            'state' => $office['State'] ?? '',
                            'country' => 'India',
                            'source' => 'external',
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Fall through to not found response.
        }

        return response()->json([
            'status' => false,
            'message' => 'Pincode not found',
        ], 404);
    }
}