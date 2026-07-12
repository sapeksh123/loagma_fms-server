<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * POST /api/auth/send-otp  { mobile }
     *
     * Looks up the staff record by mobile number. There is no OTP
     * generation/SMS gateway — the admin sets a login code directly in
     * deli_staff.password, and verifyOtp() matches against that column.
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mobile = trim($request->input('mobile'));

        $staff = DB::table('deli_staff')->where('mobile', $mobile)->first();

        if (!$staff) {
            return response()->json([
                'status' => false,
                'message' => 'This number is not registered.',
            ], 404);
        }

        if ((int) $staff->is_locked === 1) {
            return response()->json([
                'status' => false,
                'message' => 'This account is locked. Contact your admin.',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP generated',
        ]);
    }

    /**
     * POST /api/auth/verify-otp  { mobile, otp }
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'string'],
            'otp' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mobile = trim($request->input('mobile'));
        $otp = trim($request->input('otp'));

        $staff = DB::table('deli_staff')->where('mobile', $mobile)->first();

        if (!$staff) {
            return response()->json([
                'status' => false,
                'message' => 'This number is not registered.',
            ], 404);
        }

        if (!$staff->password || $staff->password !== $otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'data' => [
                'deli_id' => $staff->deli_id,
                'name' => $staff->name,
                'mobile' => $staff->mobile,
                'role' => $staff->role,
                'is_admin' => $staff->role === 'admin',
            ],
        ]);
    }
}
