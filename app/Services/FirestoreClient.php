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
    private ?string $cachedToken = null;

    private function accessToken(): ?string
    {
        return $this->cachedToken ??= $this->mintAccessToken();
    }

    private function mintAccessToken(): ?string
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

    /** Updates only the given fields, leaving the rest of the document as it is. */
    public function merge(string $collection, string $docId, array $fields): bool
    {
        $mask = implode('&', array_map(fn ($key) => 'updateMask.fieldPaths=' . rawurlencode($key), array_keys($fields)));
        [$httpCode] = $this->request('PATCH', "/{$collection}/" . rawurlencode($docId) . "?{$mask}", ['fields' => $this->wrap($fields)]);

        return $httpCode === 200;
    }

    /** True/false when known, null when Firestore couldn't be reached -- unlike get(), which returns null for both. */
    public function exists(string $collection, string $docId): ?bool
    {
        [$httpCode] = $this->request('GET', "/{$collection}/" . rawurlencode($docId) . '?mask.fieldPaths=uid');

        return match ($httpCode) {
            200 => true,
            404 => false,
            default => null,
        };
    }

    /** Atomic create: true if created, false if already there, null when Firestore couldn't be reached. */
    public function create(string $collection, string $docId, array $fields): ?bool
    {
        [$httpCode] = $this->request(
            'PATCH',
            "/{$collection}/" . rawurlencode($docId) . '?currentDocument.exists=false',
            ['fields' => $this->wrap($fields)]
        );

        if ($httpCode === 200) {
            return true;
        }

        return in_array($httpCode, [400, 409, 412], true) ? false : null;
    }

    /** Whether any document has $field == $value; null when Firestore couldn't be reached. */
    public function anyWhere(string $collection, string $field, string $value): ?bool
    {
        [$httpCode, $rows] = $this->request('POST', ':runQuery', ['structuredQuery' => [
            'from' => [['collectionId' => $collection]],
            'where' => ['fieldFilter' => [
                'field' => ['fieldPath' => $field],
                'op' => 'EQUAL',
                'value' => ['stringValue' => $value],
            ]],
            'limit' => 1,
        ]]);

        if ($httpCode !== 200 || !is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (isset($row['document'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: mixed} http status (0 when unreachable) and decoded body */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $token = $this->accessToken();
        $base = $this->baseUrl();
        if (!$token || !$base) {
            return [0, null];
        }

        $ch = curl_init($base . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result === false ? [0, null] : [$httpCode, json_decode($result, true)];
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
