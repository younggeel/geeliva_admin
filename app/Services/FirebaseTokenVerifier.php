<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Firebase Auth ID token in plain PHP, following Firebase's documented
 * manual steps (RS256 against Google's rotating certs, aud/iss/sub checks). Replaces
 * FirebaseAdminBridge for token checks: that needs proc_open to run Node, which the
 * production host disables, so every call to it threw.
 */
class FirebaseTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    private const CERTS_CACHE_KEY = 'firebase_securetoken_certs';

    /** Returns the verified uid, or null if the token is invalid or can't be checked. */
    public function uid(string $idToken): ?string
    {
        $projectId = env('FIREBASE_PROJECT_ID');
        $certs = $this->certs();
        if (empty($projectId) || empty($certs)) {
            return null;
        }

        try {
            $keys = [];
            foreach ($certs as $kid => $cert) {
                $keys[$kid] = new Key($cert, 'RS256');
            }

            JWT::$leeway = 60;
            $claims = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            return null;
        }

        $sub = $claims->sub ?? null;
        $valid = ($claims->aud ?? null) === $projectId
            && ($claims->iss ?? null) === "https://securetoken.google.com/{$projectId}"
            && is_string($sub) && $sub !== '';

        return $valid ? $sub : null;
    }

    /** @return array<string, string> */
    private function certs(): array
    {
        $cached = Cache::get(self::CERTS_CACHE_KEY);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $ch = curl_init(self::CERTS_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $certs = $httpCode === 200 ? json_decode((string) $result, true) : null;
        if (!is_array($certs) || empty($certs)) {
            Log::warning('FirebaseTokenVerifier: failed to fetch Google certs', ['status' => $httpCode]);
            return [];
        }

        // Google rotates these; an hour is well inside their published max-age.
        Cache::put(self::CERTS_CACHE_KEY, $certs, 3600);
        return $certs;
    }
}
