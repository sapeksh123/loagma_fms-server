<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        try {
            $search = trim((string) $request->get('search', ''));
            $status = trim((string) $request->get('status', ''));
            $businessType = trim((string) $request->get('business_type', ''));
            $department = trim((string) $request->get('department', ''));
            $page = max((int) $request->get('page', 1), 1);
            $perPage = min(max((int) $request->get('per_page', 20), 1), 500);

            $query = Supplier::query();

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('supplier_name', 'like', "%{$search}%")
                        ->orWhere('supplier_code', 'like', "%{$search}%")
                        ->orWhere('gst_no', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            }

            if ($status !== '') {
                $query->where('status', $status);
            }

            if ($businessType !== '') {
                $query->where('business_type', $businessType);
            }

            if ($department !== '') {
                $query->where('department', $department);
            }

            $total = (clone $query)->count();
            $data = $query->orderByDesc('id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn (Supplier $supplier) => $this->formatSupplier($supplier))
                ->values();

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
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(['status' => false, 'message' => 'Supplier not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $this->formatSupplier($supplier)]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateSupplier($request);

        $nextId = (int) (Supplier::max('id') ?? 0) + 1;

        $supplier = Supplier::create($validated + [
            'id' => $nextId,
            'metadata' => $this->normalizeMetadata($request->input('metadata')),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Supplier created successfully',
            'data' => $this->formatSupplier($supplier),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(['status' => false, 'message' => 'Supplier not found'], 404);
        }

        $validated = $this->validateSupplier($request, $supplier->id);
        $supplier->fill($validated + [
            'metadata' => $this->normalizeMetadata($request->input('metadata')),
        ]);
        $supplier->save();

        return response()->json([
            'status' => true,
            'message' => 'Supplier updated successfully',
            'data' => $this->formatSupplier($supplier->fresh()),
        ]);
    }

    public function checkPhone(string $phone)
    {
        $supplier = Supplier::where('phone', $phone)->first();

        return response()->json([
            'status' => true,
            'exists' => $supplier !== null,
            'name' => $supplier?->supplier_name,
            'id' => $supplier?->id,
        ]);
    }

    private function validateSupplier(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'supplier_code' => ['required', 'string', 'max:50'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'gst_no' => ['nullable', 'string', 'max:20'],
            'pan_no' => ['nullable', 'string', 'max:20'],
            'tan_no' => ['nullable', 'string', 'max:20'],
            'cin_no' => ['nullable', 'string', 'max:30'],
            'vat_no' => ['nullable', 'string', 'max:30'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'fssai_no' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_person_email' => ['nullable', 'email', 'max:255'],
            'contact_person_phone' => ['nullable', 'string', 'max:30'],
            'contact_person_designation' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'ifsc_code' => ['nullable', 'string', 'max:20'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'numeric'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:9.99'],
            'is_preferred' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE,SUSPENDED'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $ignoreId) {
            $phone = trim((string) $request->input('phone', ''));
            if ($phone !== '') {
                $exists = Supplier::where('phone', $phone)
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('phone', 'This phone number already exists');
                }
            }
        });

        return $validator->validate();
    }

    private function normalizeMetadata(mixed $metadata): ?array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : ['value' => $metadata];
        }

        return null;
    }

    private function formatSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->supplier_name,
            'short_name' => $supplier->short_name,
            'business_type' => $supplier->business_type,
            'department' => $supplier->department,
            'gst_no' => $supplier->gst_no,
            'pan_no' => $supplier->pan_no,
            'tan_no' => $supplier->tan_no,
            'cin_no' => $supplier->cin_no,
            'vat_no' => $supplier->vat_no,
            'registration_no' => $supplier->registration_no,
            'fssai_no' => $supplier->fssai_no,
            'website' => $supplier->website,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'alternate_phone' => $supplier->alternate_phone,
            'contact_person' => $supplier->contact_person,
            'contact_person_email' => $supplier->contact_person_email,
            'contact_person_phone' => $supplier->contact_person_phone,
            'contact_person_designation' => $supplier->contact_person_designation,
            'address_line1' => $supplier->address_line1,
            'city' => $supplier->city,
            'state' => $supplier->state,
            'country' => $supplier->country,
            'pincode' => $supplier->pincode,
            'bank_name' => $supplier->bank_name,
            'bank_branch' => $supplier->bank_branch,
            'bank_account_name' => $supplier->bank_account_name,
            'bank_account_number' => $supplier->bank_account_number,
            'ifsc_code' => $supplier->ifsc_code,
            'swift_code' => $supplier->swift_code,
            'payment_terms_days' => $supplier->payment_terms_days,
            'credit_limit' => $supplier->credit_limit,
            'rating' => $supplier->rating,
            'is_preferred' => (bool) $supplier->is_preferred,
            'status' => $supplier->status,
            'notes' => $supplier->notes,
            'metadata' => $supplier->metadata,
            'created_at' => optional($supplier->created_at)?->toDateTimeString(),
            'updated_at' => optional($supplier->updated_at)?->toDateTimeString(),
        ];
    }
}