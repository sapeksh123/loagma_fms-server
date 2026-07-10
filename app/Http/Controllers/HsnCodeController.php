<?php

namespace App\Http\Controllers;

use App\Models\HsnCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HsnCodeController extends Controller
{
    // GET /api/hsn-codes
    public function index(Request $request)
    {
        try {
            // Check if table exists
            if (!\Schema::hasTable('hsn_codes')) {
                // Return sample data if table doesn't exist (matching actual table structure)
                return response()->json([
                    'success' => true,
                    'data' => [
                        [
                            'id' => 1,
                            'hsn_code' => '1001',
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now()
                        ],
                        [
                            'id' => 2,
                            'hsn_code' => '1002',
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ],
                    'message' => 'Sample HSN codes (table not configured yet)'
                ]);
            }

            // Pagination parameters
            $limit = min(max((int) $request->get('limit', 50), 1), 100);
            $offset = max((int) $request->get('offset', 0), 0);

            $query = HsnCode::query();

            // Filter by active status if requested
            if ($request->has('active_only') && $request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            // Get total count before pagination
            $totalCount = $query->count();

            // Apply pagination
            $hsnCodes = $query
                ->orderBy('hsn_code')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $hsnCodes,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $hsnCodes->count()) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/hsn-codes/search
    public function search(Request $request)
    {
        try {
            // Pagination parameters
            $limit = min(max((int) $request->get('limit', 50), 1), 100);
            $offset = max((int) $request->get('offset', 0), 0);

            $query = HsnCode::query();

            // Apply active filter first (uses index)
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Apply search filter - use prefix search for better index usage
            if ($request->has('q') && trim($request->input('q')) !== '') {
                $searchTerm = trim($request->input('q'));
                // Prefix search is faster with indexes
                $query->where('hsn_code', 'LIKE', "{$searchTerm}%");
            }

            // Get total count before pagination
            $totalCount = $query->count();

            // Apply pagination
            $hsnCodes = $query
                ->orderBy('hsn_code')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $hsnCodes,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $hsnCodes->count()) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/hsn-codes/{id}
    public function show($id)
    {
        try {
            $hsnCode = HsnCode::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $hsnCode
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'HSN Code not found'
            ], 404);
        }
    }

    // POST /api/hsn-codes
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'hsn_code' => 'required|string|max:20|unique:hsn_codes,hsn_code', // Adjusted max length
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $data = $validator->validated();

            // Set default values
            if (!isset($data['is_active'])) {
                $data['is_active'] = true;
            }

            // Only use fields that exist in the actual table
            $hsnData = [
                'id' => (int) (HsnCode::max('id') ?? 0) + 1,
                'hsn_code' => $data['hsn_code'],
                'is_active' => $data['is_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $hsnCode = HsnCode::create($hsnData);

            return response()->json([
                'success' => true,
                'message' => 'HSN Code created successfully',
                'data' => $hsnCode
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // PUT /api/hsn-codes/{id}
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'hsn_code' => 'sometimes|required|string|max:20|unique:hsn_codes,hsn_code,' . $id,
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $hsnCode = HsnCode::findOrFail($id);
            $hsnCode->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'HSN Code updated successfully',
                'data' => $hsnCode->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'HSN Code not found'
            ], 404);
        }
    }

    // POST /api/hsn-codes/{id}/toggle-active
    public function toggleActive($id)
    {
        try {
            $hsnCode = HsnCode::findOrFail($id);
            $hsnCode->is_active = !$hsnCode->is_active;
            $hsnCode->save();

            return response()->json([
                'success' => true,
                'message' => 'HSN Code status toggled successfully',
                'data' => $hsnCode
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'HSN Code not found'
            ], 404);
        }
    }

    // DELETE /api/hsn-codes/{id}
    public function destroy($id)
    {
        try {
            $hsnCode = HsnCode::findOrFail($id);
            $hsnCode->delete();

            return response()->json([
                'success' => true,
                'message' => 'HSN Code deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'HSN Code not found'
            ], 404);
        }
    }
}
