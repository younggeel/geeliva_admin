<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Runs the small firebase-admin (Node) scripts under storage/app/firebase for the two
 * operations the Google\Client-based REST calls elsewhere in this app can't do:
 * verifying a Firebase Auth ID token, and minting a custom token. Both need the Admin
 * SDK's own logic (JWT verification against Google's rotating public keys, and JWT
 * signing with the service-account private key) rather than a generic OAuth REST call.
 */
class FirebaseAdminBridge
{
    public function run(string $scriptName, array $payload): ?array
    {
        $nodePath = env('NODE_PATH', 'node');
        $scriptPath = storage_path("app/firebase/{$scriptName}");

        $tmpFile = tempnam(sys_get_temp_dir(), 'fbadmin_');
        file_put_contents($tmpFile, json_encode($payload));

        try {
            $process = new Process([$nodePath, $scriptPath, $tmpFile]);
            $process->setTimeout(10);
            $process->run();

            $decoded = json_decode(trim($process->getOutput()), true);
            if (!$process->isSuccessful() || !is_array($decoded) || isset($decoded['error'])) {
                Log::warning('FirebaseAdminBridge: script failed', [
                    'script' => $scriptName,
                    'detail' => $decoded['error'] ?? $process->getErrorOutput(),
                ]);
                return null;
            }
            return $decoded;
        } finally {
            @unlink($tmpFile);
        }
    }
}
