<?php

use App\Http\Controllers\FreeDeliveryController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\PushNotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Mobile clients (customer/vendor/driver apps) authenticate per-request with their own
// Firebase Auth ID token (verified server-side in the controller), not a Sanctum token --
// see PushNotificationController for why. IP-based throttle here is defense in depth on
// top of the per-uid limiter inside the controller.
Route::middleware('throttle:60,1')->post('/notifications/send', [PushNotificationController::class, 'send']);

// Password reset via emailed one-time code -- unauthenticated by definition (this IS
// the recovery path for someone who can't sign in), safety comes from OtpService's
// per-key rate limiting/expiry/attempt caps, not from a Laravel auth guard here.
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/password-reset/request', [PasswordResetController::class, 'request']);
    Route::post('/password-reset/verify', [PasswordResetController::class, 'verify']);
});

// Phone verification is done natively via Firebase Auth SMS on-device
// (FirebaseAuth.currentUser.updatePhoneNumber) -- this just registers the
// already-SMS-verified number in the verified_phones lookup collection.
Route::middleware('throttle:20,1')->post('/auth/register-verified-phone', [PhoneVerificationController::class, 'register']);

// First-order free delivery, shared by the parcel and food/e-commerce checkout
// flows -- device + IP (captured server-side) + verified-phone dedup.
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/delivery/check-first-order-eligibility', [FreeDeliveryController::class, 'checkEligibility']);
    Route::post('/delivery/consume-first-order-eligibility', [FreeDeliveryController::class, 'consumeEligibility']);
});
