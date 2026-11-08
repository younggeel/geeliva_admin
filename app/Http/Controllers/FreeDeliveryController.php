<?php

namespace App\Http\Controllers;

use App\Services\FirebaseTokenVerifier;
use App\Services\FirestoreClient;
use App\Services\IdentityToolkitClient;
use Illuminate\Http\Request;

/**
 * First-order free delivery, shared by the parcel and food/e-commerce checkout
 * flows so a customer gets exactly one free delivery total, not one per service.
 * Only the delivery fee is waived; the app still charges the items and packaging.
 *
 * Requires an SMS-verified phone on the Firebase account and no previous order.
 * Abuse check uses independent, deterministically-keyed lookup collections
 * (mirrors the verified_phones pattern). A claim on ANY of device, phone, email
 * or IP blocks eligibility. Email and IP are hashed as doc ids. IP keys include a
 * time window, because many mobile customers share one public IP -- a permanent
 * block would shut out strangers on the same network.
 *
 * Anything that can't be checked counts as not eligible, so an outage can't hand
 * out free deliveries.
 */
class FreeDeliveryController extends Controller
{
    private const BY_DEVICE = 'free_delivery_claims_by_device';
    private const BY_PHONE = 'free_delivery_claims_by_phone';
    private const BY_EMAIL = 'free_delivery_claims_by_email';
    private const BY_IP = 'free_delivery_claims_by_ip';

    public function __construct(
        private FirebaseTokenVerifier $tokenVerifier,
        private IdentityToolkitClient $identity,
        private FirestoreClient $firestore
    ) {
    }

    public function checkEligibility(Request $request)
    {
        [$uid, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        $account = $this->identity->getAccount($uid);
        if ($account === null) {
            return response()->json(['eligible' => false]);
        }
        if (empty($account['phone'])) {
            return response()->json(['eligible' => false, 'reason' => 'phone_required']);
        }

        return response()->json(['eligible' => $this->isClear($uid, $this->claimKeys($request, $account))]);
    }

    public function consumeEligibility(Request $request)
    {
        [$uid, $error] = $this->authenticate($request);
        if ($error) {
            return $error;
        }

        $account = $this->identity->getAccount($uid);
        if ($account === null || empty($account['phone'])) {
            return response()->json(['eligible' => false]);
        }

        // Re-check right before recording -- closes the gap between an earlier
        // check-eligibility call and order placement.
        $keys = $this->claimKeys($request, $account);
        if (!$this->isClear($uid, $keys)) {
            return response()->json(['eligible' => false]);
        }

        // Atomic creates, so two orders placed at once can't both win.
        $claim = ['uid' => $uid, 'usedAt' => now()->toIso8601String()];
        foreach ($keys as $collection => $docId) {
            if ($this->firestore->create($collection, $docId, $claim) !== true) {
                return response()->json(['eligible' => false]);
            }
        }

        $this->firestore->merge('users', $uid, ['remainingFreeDeliveries' => 0]);

        return response()->json(['eligible' => true]);
    }

    /** @return array{0: string|null, 1: \Illuminate\Http\JsonResponse|null} */
    private function authenticate(Request $request): array
    {
        $request->validate(['id_token' => 'required|string', 'device_id' => 'required|string']);

        $uid = $this->tokenVerifier->uid($request->input('id_token'));
        if ($uid === null) {
            return [null, response()->json(['success' => false, 'message' => 'Invalid or expired credentials'], 401)];
        }

        return [$uid, null];
    }

    /**
     * @param array{phone: ?string, email: ?string} $account
     * @return array<string, string> collection => doc id
     */
    private function claimKeys(Request $request, array $account): array
    {
        $keys = [
            self::BY_DEVICE => trim($request->input('device_id')),
            self::BY_PHONE => $account['phone'],
            self::BY_IP => $this->ipKey($request->ip()),
        ];
        if (!empty($account['email'])) {
            $keys[self::BY_EMAIL] = hash('sha256', $this->canonicalEmail($account['email']));
        }

        return $keys;
    }

    /** Gmail ignores dots and +tags, so a.b+1@gmail.com is the same inbox as ab@gmail.com. */
    private function canonicalEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', explode('+', $local, 2)[0]);
            $domain = 'gmail.com';
        }

        return "{$local}@{$domain}";
    }

    private function ipKey(string $ip): string
    {
        $days = max(1, (int) env('FREE_DELIVERY_IP_BLOCK_DAYS', 30));
        $window = (int) floor(time() / ($days * 86400));

        return hash('sha256', "{$ip}|{$window}");
    }

    /** @param array<string, string> $keys */
    private function isClear(string $uid, array $keys): bool
    {
        foreach ($keys as $collection => $docId) {
            if ($this->firestore->exists($collection, $docId) !== false) {
                return false;
            }
        }

        return $this->firestore->anyWhere('vendor_orders', 'authorID', $uid) === false
            && $this->firestore->anyWhere('parcel_orders', 'authorID', $uid) === false;
    }
}
