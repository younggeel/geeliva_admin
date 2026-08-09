<?php

namespace App\Http\Controllers;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class FirebaseController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function config()
    {
        $data = array(
            'apiKey' => base64_encode(env('FIREBASE_APIKEY')),
            'authDomain' => base64_encode(env('FIREBASE_AUTH_DOMAIN')),
            'databaseURL' => base64_encode(env('FIREBASE_DATABASE_URL')),
            'projectId' => base64_encode(env('FIREBASE_PROJECT_ID')),
            'storageBucket' => base64_encode(env('FIREBASE_STORAGE_BUCKET')),
            'messagingSenderId' => base64_encode(env('FIREBASE_MESSAAGING_SENDER_ID')),
            'appId' => base64_encode(env('FIREBASE_APP_ID')),
            'measurementId' => base64_encode(env('FIREBASE_MEASUREMENT_ID')),
        );

        return response()->json($data);
    }

    /**
     * Mints a Firebase custom token carrying an `admin: true` claim, for the browser to sign
     * into Firebase Auth with. Firestore Security Rules were tightened on 2026-08-08 to
     * require a signed-in Firebase Auth user for every read/write -- this panel never had one
     * (it only ever used Laravel's own session auth), so every client-side Firestore call was
     * failing with "Missing or insufficient permissions." This restores access via a narrow,
     * explicit isAdmin() escape hatch in firestore.rules, without reopening anything the
     * security tightening fixed (see firestore.rules for exactly which collections check it).
     *
     * Gated by the same `auth` middleware as every other action here -- only someone already
     * logged into the Laravel admin panel can mint one of these.
     */
    public function adminToken()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (!Storage::disk('local')->has('firebase/credentials.json')) {
            return response()->json(['success' => false, 'message' => 'Firebase credentials missing'], 500);
        }

        $serviceAccount = json_decode(Storage::disk('local')->get('firebase/credentials.json'), true);

        $now = time();
        $payload = [
            'iss' => $serviceAccount['client_email'],
            'sub' => $serviceAccount['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now,
            'exp' => $now + 3600,
            'uid' => 'admin-panel-' . $user->id,
            'claims' => ['admin' => true],
        ];

        $jwt = JWT::encode($payload, $serviceAccount['private_key'], 'RS256');

        return response()->json(['success' => true, 'token' => $jwt]);
    }

}
