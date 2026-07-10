<?php

namespace App\Http\Controllers;

use App\Models\GeneralAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class GeneralAccountController extends Controller
{
    public function index(Request $request)
    {
        try {
            $search = trim((string) $request->get('search', ''));
            $accountType = trim((string) $request->get('account_type', ''));
            $page = max((int) $request->get('page', 1), 1);
            $perPage = min(max((int) $request->get('per_page', 20), 1), 500);

            $query = GeneralAccount::query();

            if ($search !== '') {
                // Case-insensitive search (TiDB/MySQL may use a case-sensitive collation).
                $like = '%' . mb_strtolower($search) . '%';
                $query->where(function ($builder) use ($like, $search) {
                    $builder->whereRaw('LOWER(account_no) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(account_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(account_type) LIKE ?', [$like]);
                    if (is_numeric($search)) {
                        $builder->orWhere('id', (int) $search);
                    }
                });
            }

            if ($accountType !== '') {
                $query->where('account_type', $accountType);
            }

            $total = (clone $query)->count();
            $data = $query->orderByDesc('id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn (GeneralAccount $account) => $this->formatAccount($account))
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
        $account = GeneralAccount::find($id);

        if (!$account) {
            return response()->json(['status' => false, 'message' => 'Account not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $this->formatAccount($account)]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAccount($request);

        $account = GeneralAccount::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Account created successfully',
            'data' => $this->formatAccount($account),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $account = GeneralAccount::find($id);

        if (!$account) {
            return response()->json(['status' => false, 'message' => 'Account not found'], 404);
        }

        $validated = $this->validateAccount($request, $id);
        $account->fill($validated);
        $account->save();

        return response()->json([
            'status' => true,
            'message' => 'Account updated successfully',
            'data' => $this->formatAccount($account->fresh()),
        ]);
    }

    public function checkAccountNo(string $accountNo, Request $request)
    {
        $ignoreId = $request->integer('ignore_id');

        $account = GeneralAccount::query()
            ->where('account_no', $accountNo)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();

        return response()->json([
            'status' => true,
            'exists' => $account !== null,
            'id' => $account?->id,
            'account_name' => $account?->account_name,
            'account_type' => $account?->account_type,
        ]);
    }

    private function validateAccount(Request $request, ?int $ignoreId = null): array
    {
        $validator = Validator::make($request->all(), [
            'account_no' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'max:100',
                Rule::unique('general_account', 'account_no')->ignore($ignoreId),
            ],
            'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'string', 'max:100'],
        ], [
            'account_no.regex' => 'Account no must contain numbers only',
            'account_no.unique' => 'Account no already exists',
        ]);

        return $validator->validate();
    }

    private function formatAccount(GeneralAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_no' => $account->account_no,
            'account_name' => $account->account_name,
            'account_type' => $account->account_type,
            'created_at' => optional($account->created_at)?->toDateTimeString(),
        ];
    }
}