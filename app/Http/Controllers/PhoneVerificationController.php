<?php

namespace App\Http\Controllers;

use App\Services\FirebaseAdminBridge;
use App\Services\FirestoreClient;
use App\Services\IdentityToolkitClient;
use Illuminate\Http\Request;

/**
 * Registers a caller's phone number in the verified_phones lookup collection
 * (used by "buy for someone" recipient search) after the client has already
 * SMS-verified the number natively via Firebase Auth's own phone-auth flow
 * (FirebaseAuth.currentUser.updatePhoneNumber). No OTP is generated or checked
 * here -- Firebase already proved the number before this endpoint is called;
 * this just confirms it via the Identity Toolkit and records the lookup entry.
 */
class PhoneVerificationController extends Controller
{
    private const VERIFIED_PHONES_COLLECTION = 'verified_phones';

    public function __construct(
        private FirebaseAdminBridge $adminBridge,
        private IdentityToolkitClient $identity,
        private FirestoreClient $firestore
    ) {
    }

    public function register(Request $request)
    {
        $request->validate(['id_token' => 'required|string']);

        $result = $this->adminBridge->run('verifyFirebaseToken.js', ['idToken' => $request->input('id_token')]);
        $uid = $result['uid'] ?? null;
        if ($uid === null) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired credentials'], 401);
        }

        $phone = $this->identity->getPhoneNumberByUid($uid);
        if (empty($phone)) {
            return response()->json(['success' => false, 'message' => 'No verified phone number found on this account.'], 400);
        }

        $this->firestore->set(self::VERIFIED_PHONES_COLLECTION, $phone, ['uid' => $uid]);

        return response()->json(['success' => true, 'phone' => $phone]);
    }
}
