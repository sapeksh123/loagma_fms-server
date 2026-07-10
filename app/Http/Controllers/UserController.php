<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $search = trim((string) $request->get('search', ''));
            $accountState = trim((string) $request->get('account_state', ''));
            $approval = trim((string) $request->get('approval', ''));
            $userType = trim((string) $request->get('user_type', ''));
            $page = max((int) $request->get('page', 1), 1);
            $perPage = min(max((int) $request->get('per_page', 20), 1), 500);

            $query = User::query();

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('contactno', 'like', "%{$search}%")
                        ->orWhere('shop_name', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('userid', (int) $search);
                    }
                });
            }

            if ($accountState !== '') {
                $query->where('account_state', $accountState);
            }

            if ($approval !== '') {
                $query->where('is_approved', $approval);
            }

            if ($userType !== '') {
                $query->where('user_type', $userType);
            }

            $total = (clone $query)->count();
            $data = $query->orderByDesc('userid')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn (User $user) => $this->formatUser($user))
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
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $this->formatUser($user)]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateUser($request);

        $validated['register_date'] = $validated['register_date'] ?? time();
        $validated['userid'] = (int) (User::max('userid') ?? 0) + 1;
        $validated['session_id'] = $validated['session_id'] ?? '';
        $validated['push_notif_id'] = $validated['push_notif_id'] ?? '';
        $validated['latitude'] = $validated['latitude'] ?? 0;
        $validated['longitude'] = $validated['longitude'] ?? 0;

        $user = User::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'User created successfully',
            'data' => $this->formatUser($user),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        $validated = $this->validateUser($request, $user->id);
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->fill($validated);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data' => $this->formatUser($user->fresh()),
        ]);
    }

    public function checkContact(string $phone)
    {
        $user = User::where('contactno', $phone)->first();

        return response()->json([
            'status' => true,
            'exists' => $user !== null,
            'name' => $user?->name,
            'id' => $user?->id,
        ]);
    }

    private function validateUser(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'max:250'],
            'contactno' => ['required', 'string', 'max:250'],
            'password' => [$ignoreId ? 'nullable' : 'required', 'string', 'min:6'],
            'account_state' => ['nullable', 'string', 'max:250'],
            'address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'dob' => ['nullable', 'string'],
            'register_date' => ['nullable', 'integer'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'shop_address' => ['nullable', 'string', 'max:255'],
            'shop_plot_no' => ['nullable', 'string', 'max:255'],
            'user_type' => ['nullable', 'in:B2C,B2B'],
            'adhar_card' => ['nullable', 'string', 'max:255'],
            'shop_photo' => ['nullable', 'string', 'max:255'],
            'shop_licence' => ['nullable', 'string', 'max:255'],
            'bussiness_pan_card' => ['nullable', 'string', 'max:255'],
            'is_approved' => ['nullable', 'in:YES,NO,REQUESTED'],
            'session_id' => ['nullable', 'string'],
            'last_activity' => ['nullable', 'integer'],
            'push_notif_id' => ['nullable', 'string'],
            'is_first_login' => ['nullable', 'boolean'],
            'has_unread_comments' => ['nullable', 'boolean'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'is_email_verified' => ['nullable', 'boolean'],
            'is_contact_verified' => ['nullable', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $ignoreId) {
            $phone = trim((string) $request->input('contactno', ''));
            if ($phone !== '') {
                $exists = User::where('contactno', $phone)
                    ->when($ignoreId, fn ($q) => $q->where('userid', '!=', $ignoreId))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('contactno', 'This contact number already exists');
                }
            }

            $email = trim((string) $request->input('email', ''));
            if ($email !== '') {
                $exists = User::where('email', $email)
                    ->when($ignoreId, fn ($q) => $q->where('userid', '!=', $ignoreId))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('email', 'This email already exists');
                }
            }
        });

        return $validator->validate();
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'userid' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_email_verified' => (bool) $user->is_email_verified,
            'contactno' => $user->contactno,
            'is_contact_verified' => (bool) $user->is_contact_verified,
            'account_state' => $user->account_state,
            'address' => $user->address,
            'latitude' => $user->latitude,
            'longitude' => $user->longitude,
            'dob' => $user->dob,
            'register_date' => $user->register_date,
            'shop_name' => $user->shop_name,
            'shop_address' => $user->shop_address,
            'shop_plot_no' => $user->shop_plot_no,
            'user_type' => $user->user_type,
            'adhar_card' => $user->adhar_card,
            'shop_photo' => $user->shop_photo,
            'shop_licence' => $user->shop_licence,
            'bussiness_pan_card' => $user->bussiness_pan_card,
            'is_approved' => $user->is_approved,
            'session_id' => $user->session_id,
            'last_activity' => $user->last_activity,
            'push_notif_id' => $user->push_notif_id,
            'is_first_login' => (bool) $user->is_first_login,
            'has_unread_comments' => (bool) $user->has_unread_comments,
            'pincode' => $user->pincode,
            'city' => $user->city,
            'state' => $user->state,
            'created_at' => optional($user->created_at)?->toDateTimeString(),
            'updated_at' => optional($user->updated_at)?->toDateTimeString(),
        ];
    }
}