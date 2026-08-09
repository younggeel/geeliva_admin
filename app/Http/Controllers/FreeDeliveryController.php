<?php

namespace App\Http\Controllers;

use App\Services\FirebaseAdminBridge;
use App\Services\FirestoreClient;
use App\Services\IdentityToolkitClient;
use Illuminate\Http\Request;

/**
 * First-order free delivery, shared by the parcel and food/e-commerce checkout
 * flows so a customer gets exactly one free delivery total, not one per service.
 *
 * Abuse check uses three independent, deterministically-keyed lookup collections
 * (mirrors the verified_phones pattern) rather than a single queryable
 * collection, because FirestoreClient only supports get/set by known doc id --
 * no server-side "where field == value" query. A claim on ANY of device, phone,
 * or IP blocks eligibility; ip is hashed as the doc id to avoid relying on
 * IPv6-safe Firestore doc-id characters.
 */
class FreeDeliveryController extends Controller
{
    private const BY_DEVICE = 'free_delivery_claims_by_device';
    private const BY_PHONE = 'free_delivery_claims_by_phone';
    private const BY_IP = 'free_delivery_claims_by_ip';

    public function __construct(
        private FirebaseAdminBridge $adminBridge,
        private IdentityToolkitClient $identity,
        private FirestoreClient $firestore
    ) {
    }

    public function checkEligibility(Request $request)
    {
        [$ok, $uid, $error] = $this->authenticate($request);
        if (!$ok) {
            return $error;
        }

        $eligible = !$this->hasAnyClaim($uid, $this->deviceId($request), $this->phone($uid), $request->ip());

        return response()->json(['eligible' => $eligible]);
    }

    public function consumeEligibility(Request $request)
    {
        [$ok, $uid, $error] = $this->authenticate($request);
        if (!$ok) {
            return $error;
        }

        $deviceId = $this->deviceId($request);
        $phone = $this->phone($uid);
        $ip = $request->ip();

        // Re-check right before recording -- closes the gap between an earlier
        // check-eligibility call and order placement.
        if ($this->hasAnyClaim($uid, $deviceId, $phone, $ip)) {
            return response()->json(['eligible' => false]);
        }

        $claim = ['uid' => $uid, 'deviceId' => $deviceId, 'phone' => $phone ?? '', 'ip' => $ip, 'usedAt' => now()->toIso8601String()];
        $this->firestore->set(self::BY_DEVICE, $deviceId, $claim);
        if (!empty($phone)) {
            $this->firestore->set(self::BY_PHONE, $phone, $claim);
        }
        $this->firestore->set(self::BY_IP, $this->ipKey($ip), $claim);
        $this->firestore->set('users', $uid, ['remainingFreeDeliveries' => 0]);

        return response()->json(['eligible' => true]);
    }

    /** @return array{0: bool, 1: string|null, 2: \Illuminate\Http\JsonResponse|null} */
    private function authenticate(Request $request): array
    {
        $request->validate(['id_token' => 'required|string', 'device_id' => 'required|string']);

        $result = $this->adminBridge->run('verifyFirebaseToken.js', ['idToken' => $request->input('id_token')]);
        $uid = $result['uid'] ?? null;
        if ($uid === null) {
            return [false, null, response()->json(['success' => false, 'message' => 'Invalid or expired credentials'], 401)];
        }

        return [true, $uid, null];
    }

    private function deviceId(Request $request): string
    {
        return trim($request->input('device_id'));
    }

    private function phone(string $uid): ?string
    {
        return $this->identity->getPhoneNumberByUid($uid);
    }

    private function ipKey(string $ip): string
    {
        return hash('sha256', $ip);
    }

    private function hasAnyClaim(string $uid, string $deviceId, ?string $phone, string $ip): bool
    {
        if ($this->firestore->get(self::BY_DEVICE, $deviceId) !== null) {
            return true;
        }
        if (!empty($phone) && $this->firestore->get(self::BY_PHONE, $phone) !== null) {
            return true;
        }
        if ($this->firestore->get(self::BY_IP, $this->ipKey($ip)) !== null) {
            return true;
        }

        return false;
    }
}
