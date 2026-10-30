<?php

namespace App\Http\Controllers;

use App\Mail\SetEmailData;
use App\Services\IdentityToolkitClient;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Password reset via emailed one-time code instead of Firebase's default
 * clickable-link email -- see security assessment for why (short answer:
 * code vs. link is a wash security-wise per NIST 800-63B since both ride on
 * the same mailbox, but this gives us control over rate limiting/expiry that
 * the stock Firebase flow doesn't).
 */
class PasswordResetController extends Controller
{
    private const OTP_COLLECTION = 'password_reset_otps';

    public function __construct(private OtpService $otp, private IdentityToolkitClient $identity)
    {
    }

    public function request(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($request->input('email')));

        $genericResponse = response()->json(['message' => 'If that email exists, a reset code has been sent.']);

        $emailLimitKey = 'pwreset-email:' . $email;
        $ipLimitKey = 'pwreset-ip:' . $request->ip();
        // Rate-limited *before* revealing anything -- don't let the response differ
        // based on whether the limiter tripped, that itself would leak information.
        // First send + 3 resends per 30 minutes, matching the apps' "Resend code" limit.
        if (RateLimiter::tooManyAttempts($emailLimitKey, 4) || RateLimiter::tooManyAttempts($ipLimitKey, 10)) {
            return $genericResponse;
        }
        RateLimiter::hit($emailLimitKey, 1800);
        RateLimiter::hit($ipLimitKey, 3600);

        $uid = $this->identity->lookupUidByEmail($email);
        if ($uid !== null) {
            $code = $this->otp->generateCode();
            $this->otp->issue(self::OTP_COLLECTION, $email, $code, 10);
            try {
                Mail::to($email)->send(new SetEmailData(
                    'Your Geeliva password reset code',
                    "Your password reset code is <b>{$code}</b>. It expires in 10 minutes.<br>If you didn't request this, you can safely ignore this email."
                ));
            } catch (\Throwable $e) {
                Log::error('PasswordResetController: failed to send reset email', ['error' => $e->getMessage()]);
            }
        }

        // Same response whether or not the account exists -- avoids the
        // user-enumeration issue OWASP flags for password-reset endpoints.
        return $genericResponse;
    }

    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'newPassword' => 'required|string|min:6',
        ]);
        $email = strtolower(trim($request->input('email')));

        $result = $this->otp->verify(self::OTP_COLLECTION, $email, $request->input('code'));
        if (!$result['ok']) {
            return response()->json(['success' => false, 'message' => $this->messageFor($result['reason'])], 400);
        }

        $uid = $this->identity->lookupUidByEmail($email);
        if ($uid === null) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        // The code was delivered to this inbox, which proves ownership of the address.
        if (!$this->identity->updatePassword($uid, $request->input('newPassword'), markEmailVerified: true)) {
            return response()->json(['success' => false, 'message' => 'Could not update password, please try again.'], 500);
        }

        return response()->json(['success' => true]);
    }

    private function messageFor(?string $reason): string
    {
        return match ($reason) {
            'expired' => 'This code has expired. Request a new one.',
            'too_many_attempts' => 'Too many incorrect attempts. Request a new code.',
            default => 'Invalid code.',
        };
    }
}
