<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        try {
            if (!\Schema::hasTable('taxes')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Taxes table not configured yet',
                ]);
            }

            // Pagination parameters
            $limit = min(max((int) $request->get('limit', 50), 1), 100);
            $offset = max((int) $request->get('offset', 0), 0);

            $query = Tax::query();

            if ($request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            // Get total count before pagination
            $totalCount = $query->count();

            // Apply pagination and ordering
            $taxes = $query
                ->orderBy('tax_category')
                ->orderBy('tax_sub_category')
                ->orderBy('tax_name')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $taxes,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $taxes->count()) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            // Pagination parameters
            $limit = min(max((int) $request->get('limit', 50), 1), 100);
            $offset = max((int) $request->get('offset', 0), 0);

            $query = Tax::query();

            // Apply active filter first (uses index)
            if ($request->has('active_only') && $request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            // Apply search filter
            if ($request->has('q') && trim($request->input('q')) !== '') {
                $searchTerm = trim($request->input('q'));

                // Use indexed columns for better performance
                $query->where(function ($qry) use ($searchTerm) {
                    $qry->where('tax_category', 'LIKE', "{$searchTerm}%")
                        ->orWhere('tax_sub_category', 'LIKE', "{$searchTerm}%")
                        ->orWhere('tax_name', 'LIKE', "{$searchTerm}%");
                });
            }

            // Filter by category if provided
            if ($request->has('category') && trim($request->input('category')) !== '') {
                $query->where('tax_category', $request->input('category'));
            }

            // Filter by subcategory if provided
            if ($request->has('subcategory') && trim($request->input('subcategory')) !== '') {
                $query->where('tax_sub_category', $request->input('subcategory'));
            }

            // Get total count before pagination
            $totalCount = $query->count();

            // Apply pagination and ordering
            $taxes = $query
                ->orderBy('tax_category')
                ->orderBy('tax_sub_category')
                ->orderBy('tax_name')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $taxes,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $taxes->count()) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $tax = Tax::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $tax,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found',
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_category' => 'required|string|max:100',
            'tax_sub_category' => 'required|string|max:100',
            'tax_name' => 'required|string|max:150',
            'is_active' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }
        try {
            $data = $validator->validated();
            if (!isset($data['is_active'])) {
                $data['is_active'] = true;
            }
            $data['id'] = (int) (Tax::max('id') ?? 0) + 1;
            $tax = Tax::create($data);
            return response()->json([
                'success' => true,
                'message' => 'Tax created successfully',
                'data' => $tax,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'tax_category' => 'sometimes|required|string|max:100',
            'tax_sub_category' => 'sometimes|required|string|max:100',
            'tax_name' => 'sometimes|required|string|max:150',
            'is_active' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }
        try {
            $tax = Tax::findOrFail($id);
            $tax->update($validator->validated());
            return response()->json([
                'success' => true,
                'message' => 'Tax updated successfully',
                'data' => $tax->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found',
            ], 404);
        }
    }

    public function toggleActive($id)
    {
        try {
            $tax = Tax::findOrFail($id);
            $tax->is_active = !$tax->is_active;
            $tax->save();
            return response()->json([
                'success' => true,
                'message' => 'Tax status toggled successfully',
                'data' => $tax,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found',
            ], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $tax = Tax::findOrFail($id);
            $tax->delete();
            return response()->json([
                'success' => true,
                'message' => 'Tax deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tax not found',
            ], 404);
        }
    }
}
