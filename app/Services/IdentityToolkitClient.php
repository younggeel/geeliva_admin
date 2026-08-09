<?php

namespace App\Services;

use Google\Client as Google_Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Identity Toolkit Admin REST API (the same API surface Firebase Admin SDKs call
 * internally for getUserByEmail/updateUser) -- used server-side with the service
 * account's OAuth token, not a per-user ID token or the public Web API key.
 * Docs: https://docs.cloud.google.com/identity-platform/docs/reference/rest/v1/projects.accounts
 */
class IdentityToolkitClient
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
            Log::warning('IdentityToolkitClient: failed to mint access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function projectId(): ?string
    {
        return env('FIREBASE_PROJECT_ID');
    }

    private function lookup(string $field, string $value): ?string
    {
        $token = $this->accessToken();
        $projectId = $this->projectId();
        if (!$token || !$projectId) {
            return null;
        }

        $ch = curl_init("https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:lookup");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([$field => [$value], 'targetProjectId' => $projectId]));
        $result = curl_exec($ch);
        curl_close($ch);
        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);
        return $decoded['users'][0]['localId'] ?? null;
    }

    public function lookupUidByEmail(string $email): ?string
    {
        return $this->lookup('email', $email);
    }

    public function lookupUidByPhone(string $phoneNumber): ?string
    {
        return $this->lookup('phoneNumber', $phoneNumber);
    }

    /** Returns the Firebase-Auth-verified phone number on this account, if any. */
    public function getPhoneNumberByUid(string $uid): ?string
    {
        $token = $this->accessToken();
        $projectId = $this->projectId();
        if (!$token || !$projectId) {
            return null;
        }

        $ch = curl_init("https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:lookup");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['localId' => [$uid], 'targetProjectId' => $projectId]));
        $result = curl_exec($ch);
        curl_close($ch);
        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);
        return $decoded['users'][0]['phoneNumber'] ?? null;
    }

    public function updatePassword(string $uid, string $newPassword): bool
    {
        return $this->update($uid, ['password' => $newPassword]);
    }

    /** $phoneNumber must be E.164 (e.g. "+237650000000"). */
    public function updatePhoneNumber(string $uid, string $phoneNumber): bool
    {
        return $this->update($uid, ['phoneNumber' => $phoneNumber]);
    }

    private function update(string $uid, array $fields): bool
    {
        $token = $this->accessToken();
        $projectId = $this->projectId();
        if (!$token || !$projectId) {
            return false;
        }

        $ch = curl_init("https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:update");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_merge(['localId' => $uid], $fields)));
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $httpCode === 200;
    }
}
