<?php

namespace App\Services;

use Google\Client as Google_Client;
use Illuminate\Support\Facades\Storage;

/**
 * Minimal Firestore REST client (get/set/delete a single document), using the
 * same service-account token pattern as ShareLinkController/AdminFcmController.
 * Not a full Firestore SDK -- just enough for simple keyed documents (OTP
 * records, etc.) where a full query capability isn't needed.
 */
class FirestoreClient
{
    private function accessToken(): ?string
    {
        if (!Storage::disk('local')->has('firebase/credentials.json')) {
            return null;
        }
        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/firebase/credentials.json'));
            $client->addScope('https://www.googleapis.com/auth/cloud-platform');
            $client->refreshTokenWithAssertion();
            return $client->getAccessToken()['access_token'] ?? null;
        } catch (\Throwable $e) {
            \Log::warning('FirestoreClient: failed to mint access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function baseUrl(): ?string
    {
        $projectId = env('FIREBASE_PROJECT_ID');
        if (empty($projectId)) {
            return null;
        }
        return "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";
    }

    public function get(string $collection, string $docId): ?array
    {
        $token = $this->accessToken();
        $base = $this->baseUrl();
        if (!$token || !$base) {
            return null;
        }

        $ch = curl_init("{$base}/{$collection}/" . rawurlencode($docId));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            return null;
        }
        $decoded = json_decode($result, true);
        return isset($decoded['fields']) ? $this->unwrap($decoded['fields']) : null;
    }

    /** Full overwrite of the document's fields (Firestore PATCH with no updateMask replaces the doc). */
    public function set(string $collection, string $docId, array $fields): bool
    {
        $token = $this->accessToken();
        $base = $this->baseUrl();
        if (!$token || !$base) {
            return false;
        }

        $ch = curl_init("{$base}/{$collection}/" . rawurlencode($docId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['fields' => $this->wrap($fields)]));
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $httpCode === 200;
    }

    public function delete(string $collection, string $docId): void
    {
        $token = $this->accessToken();
        $base = $this->baseUrl();
        if (!$token || !$base) {
            return;
        }

        $ch = curl_init("{$base}/{$collection}/" . rawurlencode($docId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }

    private function wrap(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (is_bool($value)) {
                $out[$key] = ['booleanValue' => $value];
            } elseif (is_int($value)) {
                $out[$key] = ['integerValue' => (string) $value];
            } elseif (is_float($value)) {
                $out[$key] = ['doubleValue' => $value];
            } else {
                $out[$key] = ['stringValue' => (string) $value];
            }
        }
        return $out;
    }

    private function unwrap(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (array_key_exists('stringValue', $value)) {
                $out[$key] = $value['stringValue'];
            } elseif (array_key_exists('integerValue', $value)) {
                $out[$key] = (int) $value['integerValue'];
            } elseif (array_key_exists('doubleValue', $value)) {
                $out[$key] = $value['doubleValue'];
            } elseif (array_key_exists('booleanValue', $value)) {
                $out[$key] = $value['booleanValue'];
            }
        }
        return $out;
    }
}
