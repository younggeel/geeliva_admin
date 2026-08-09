<?php

namespace App\Services;

/**
 * Shared one-time-code core: generate, store (hashed, expiring, attempt-limited),
 * and verify. Used by password reset (email channel).
 */
class OtpService
{
    public function __construct(private FirestoreClient $firestore) {}

    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function docId(string $key): string
    {
        return hash('sha256', strtolower(trim($key)));
    }

    public function issue(string $collection, string $key, string $code, int $ttlMinutes = 10): void
    {
        $this->firestore->set($collection, $this->docId($key), [
            'codeHash' => password_hash($code, PASSWORD_BCRYPT),
            'expiresAt' => now()->addMinutes($ttlMinutes)->timestamp,
            'attempts' => 0,
        ]);
    }

    /**
     * @return array{ok: bool, reason: string|null} reason is one of
     *   'invalid'|'expired'|'too_many_attempts' when ok is false.
     */
    public function verify(string $collection, string $key, string $code, int $maxAttempts = 5): array
    {
        $docId = $this->docId($key);
        $doc = $this->firestore->get($collection, $docId);

        if ($doc === null) {
            return ['ok' => false, 'reason' => 'invalid'];
        }
        if (($doc['attempts'] ?? 0) >= $maxAttempts) {
            $this->firestore->delete($collection, $docId);
            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }
        if (($doc['expiresAt'] ?? 0) < now()->timestamp) {
            $this->firestore->delete($collection, $docId);
            return ['ok' => false, 'reason' => 'expired'];
        }
        if (!password_verify($code, $doc['codeHash'] ?? '')) {
            $this->firestore->set($collection, $docId, [
                'codeHash' => $doc['codeHash'],
                'expiresAt' => $doc['expiresAt'],
                'attempts' => ($doc['attempts'] ?? 0) + 1,
            ]);
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $this->firestore->delete($collection, $docId); // single-use
        return ['ok' => true, 'reason' => null];
    }
}
