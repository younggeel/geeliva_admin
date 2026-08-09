<?php

namespace App\Http\Controllers;

use App\Services\FirebaseAdminBridge;
use Google\Client as Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Server-mediated FCM send for the customer/vendor/driver mobile apps.
 *
 * Replaces the previous design where those apps carried a Firebase Admin SDK
 * service-account key client-side just to call the FCM send API directly
 * (OWASP Mobile Top 10 2024 M1: Improper Credential Usage). The service-account
 * key now never leaves this server; mobile clients instead prove who they are
 * with their own Firebase Auth ID token, verified here before any send happens.
 */
class PushNotificationController extends Controller
{
    public function __construct(private FirebaseAdminBridge $adminBridge)
    {
    }

    public function send(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
            'fcm_token' => 'required|string|min:20',
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:1000',
            'data' => 'nullable|array',
        ]);

        $uid = $this->verifyFirebaseIdToken($request->input('id_token'));
        if ($uid === null) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired credentials'], 401);
        }

        $rateLimitKey = 'push-send:' . $uid;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            return response()->json(['success' => false, 'message' => 'Too many requests, slow down'], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (!Storage::disk('local')->has('firebase/credentials.json')) {
            return response()->json(['success' => false, 'message' => 'Server misconfigured'], 500);
        }

        $projectId = env('FIREBASE_PROJECT_ID');
        if (empty($projectId)) {
            return response()->json(['success' => false, 'message' => 'Server misconfigured'], 500);
        }

        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/firebase/credentials.json'));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->refreshTokenWithAssertion();
            $accessToken = $client->getAccessToken()['access_token'] ?? null;
            if (empty($accessToken)) {
                return response()->json(['success' => false, 'message' => 'Unable to obtain send credentials'], 500);
            }

            $payload = [
                'message' => [
                    'token' => $request->input('fcm_token'),
                    'notification' => [
                        'title' => $request->input('title'),
                        'body' => $request->input('body'),
                    ],
                    'data' => $request->input('data', []),
                ],
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // Certificate validation intentionally left at curl's secure default here
            // (do NOT disable CURLOPT_SSL_VERIFYPEER/HOST -- see security report).
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            Log::info('push-send', ['uid' => $uid, 'fcm_token_prefix' => substr($request->input('fcm_token'), 0, 12), 'http_code' => $httpCode]);

            if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                return response()->json(['success' => false, 'message' => $curlError ?: 'FCM send failed', 'status' => $httpCode], 502);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('push-send failed', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Send failed'], 500);
        }
    }

    /**
     * Verifies a Firebase Auth ID token via FirebaseAdminBridge, rather than trusting
     * anything the client claims about itself. Returns the verified uid, or null.
     */
    private function verifyFirebaseIdToken(string $idToken): ?string
    {
        $result = $this->adminBridge->run('verifyFirebaseToken.js', ['idToken' => $idToken]);
        return $result['uid'] ?? null;
    }
}
