<?php

namespace App\Http\Controllers;

use Google\Client as Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AdminFcmController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Persist admin web FCM token to Firestore (server-side, bypasses client rules).
     */
    public function saveToken(Request $request)
    {
        $request->validate([
            'fcmToken' => 'required|string|min:20',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $projectId = env('FIREBASE_PROJECT_ID');
        if (empty($projectId)) {
            return response()->json(['success' => false, 'message' => 'FIREBASE_PROJECT_ID missing'], 500);
        }

        if (!Storage::disk('local')->has('firebase/credentials.json')) {
            return response()->json(['success' => false, 'message' => 'Firebase credentials missing'], 500);
        }

        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/firebase/credentials.json'));
            $client->addScope('https://www.googleapis.com/auth/datastore');
            $client->addScope('https://www.googleapis.com/auth/cloud-platform');
            $client->refreshTokenWithAssertion();
            $tokenData = $client->getAccessToken();
            $accessToken = $tokenData['access_token'] ?? null;
            if (empty($accessToken)) {
                return response()->json(['success' => false, 'message' => 'Unable to get Google access token'], 500);
            }

            $docId = (string) $user->id;
            $url = 'https://firestore.googleapis.com/v1/projects/' . $projectId
                . '/databases/(default)/documents/admin_fcm_tokens/' . rawurlencode($docId)
                . '?updateMask.fieldPaths=userId'
                . '&updateMask.fieldPaths=email'
                . '&updateMask.fieldPaths=name'
                . '&updateMask.fieldPaths=fcmToken'
                . '&updateMask.fieldPaths=platform'
                . '&updateMask.fieldPaths=updatedAt';

            $payload = [
                'fields' => [
                    'userId' => ['stringValue' => $docId],
                    'email' => ['stringValue' => (string) ($user->email ?? '')],
                    'name' => ['stringValue' => (string) ($user->name ?? '')],
                    'fcmToken' => ['stringValue' => (string) $request->input('fcmToken')],
                    'platform' => ['stringValue' => 'web'],
                    'updatedAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
                ],
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                return response()->json(['success' => false, 'message' => $curlError ?: 'Firestore write failed'], 500);
            }

            $decoded = json_decode($result, true);
            if ($httpCode < 200 || $httpCode >= 300) {
                return response()->json([
                    'success' => false,
                    'message' => 'Firestore write rejected',
                    'status' => $httpCode,
                    'result' => $decoded,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Admin FCM token saved',
                'userId' => $docId,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
