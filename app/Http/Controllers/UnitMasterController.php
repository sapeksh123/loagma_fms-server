<?php

namespace App\Http\Controllers;

use App\Models\UnitMaster;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UnitMasterController extends Controller
{
    public function index(Request $request)
    {
        return $this->listUnits($request);
    }

    public function search(Request $request)
    {
        return $this->listUnits($request);
    }

    public function show($id)
    {
        try {
            $unit = UnitMaster::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $unit,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unit not found',
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'unit_name' => 'required|string|max:100|unique:units_master,unit_name',
            'serial_no' => 'nullable|integer',
            'conversion_rate' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return $this->validationFailureResponse($validator);
        }

        try {
            $data = $validator->validated();
            $unit = UnitMaster::create([
                'unit_id' => (int) (UnitMaster::max('unit_id') ?? 0) + 1,
                'unit_name' => trim($data['unit_name']),
                'serial_no' => $data['serial_no'] ?? null,
                'conversion_rate' => $data['conversion_rate'],
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Unit created successfully',
                'data' => $unit,
            ], 201);
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit name already exists',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
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
            'unit_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('units_master', 'unit_name')->ignore($id, 'unit_id'),
            ],
            'serial_no' => 'sometimes|nullable|integer',
            'conversion_rate' => 'sometimes|required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return $this->validationFailureResponse($validator);
        }

        try {
            $unit = UnitMaster::findOrFail($id);
            $data = $validator->validated();

            if (array_key_exists('unit_name', $data)) {
                $data['unit_name'] = trim($data['unit_name']);
            }

            $unit->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Unit updated successfully',
                'data' => $unit->fresh(),
            ]);
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit name already exists',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unit not found',
            ], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $unit = UnitMaster::findOrFail($id);
            $unit->delete();

            return response()->json([
                'success' => true,
                'message' => 'Unit deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unit not found',
            ], 404);
        }
    }

    private function listUnits(Request $request)
    {
        try {
            if (!\Schema::hasTable('units_master')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'limit' => 0,
                        'offset' => 0,
                        'has_more' => false,
                    ],
                    'message' => 'Units table not configured yet',
                ]);
            }

            $limit = min(max((int) $request->get('limit', 50), 1), 100);
            $offset = max((int) $request->get('offset', 0), 0);
            $searchTerm = trim((string) $request->input('q', ''));

            $query = UnitMaster::query();

            if ($searchTerm !== '') {
                $query->where(function ($qry) use ($searchTerm) {
                    $qry->where('unit_name', 'LIKE', $searchTerm . '%');

                    if (is_numeric($searchTerm)) {
                        $qry->orWhere('serial_no', (int) $searchTerm)
                            ->orWhereRaw('CAST(serial_no AS CHAR) LIKE ?', [$searchTerm . '%']);
                    }
                });
            }

            if ($request->filled('serial_no')) {
                $query->where('serial_no', (int) $request->input('serial_no'));
            }

            $totalCount = $query->count();

            $units = $query
                ->orderByRaw('serial_no IS NULL, serial_no ASC')
                ->orderBy('unit_name')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $units,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $units->count()) < $totalCount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function validationFailureResponse($validator)
    {
        $errors = $validator->errors();
        $statusCode = $errors->has('unit_name') ? 409 : 400;

        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
        ], $statusCode);
    }
}
