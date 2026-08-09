<?php

namespace App\Http\Controllers;

use Google\Client as Google_Client;
use Illuminate\Support\Facades\Storage;

/**
 * Public preview pages for shared stores/products (https://shared.geeliva-cm.com/s/...).
 * Reads Firestore server-side using the same access-token pattern as
 * AdminFcmController::saveToken -- no client ever touches this credential.
 */
class ShareLinkController extends Controller
{
    public function store(string $id)
    {
        $doc = $this->fetchDocument('vendors', $id);
        if ($doc === null) {
            return $this->notFoundView('store');
        }

        return view('share.store', [
            'id' => $id,
            'title' => $doc['title'] ?? 'Store',
            'description' => $doc['description'] ?? $doc['location'] ?? '',
            'photo' => $doc['photo'] ?? null,
            'deepLink' => "geeliva-cm://s/store/{$id}",
            'pageUrl' => url("/s/store/{$id}"),
        ]);
    }

    public function item(string $id)
    {
        $doc = $this->fetchDocument('vendor_products', $id);
        if ($doc === null) {
            return $this->notFoundView('item');
        }

        return view('share.item', [
            'id' => $id,
            'title' => $doc['name'] ?? 'Item',
            'description' => $doc['description'] ?? '',
            'photo' => $doc['photo'] ?? null,
            'price' => $doc['price'] ?? null,
            'deepLink' => "geeliva-cm://s/item/{$id}",
            'pageUrl' => url("/s/item/{$id}"),
        ]);
    }

    /**
     * Apple's CDN requires this served with an application/json content-type and no
     * file extension -- a route guarantees the header regardless of web-server static
     * MIME config, unlike dropping a plain file in public/.well-known.
     */
    public function appleAppSiteAssociation()
    {
        return response()->json([
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => '3ZZ2YVsW58.com.geelivacm.customer',
                        'paths' => ['/s/*'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * GETs a single Firestore document via REST and returns its fields as a flat
     * PHP array (Firestore's typed-value wrapper unwrapped), or null if missing.
     */
    private function fetchDocument(string $collection, string $id): ?array
    {
        if (!Storage::disk('local')->has('firebase/credentials.json')) {
            return null;
        }
        $projectId = env('FIREBASE_PROJECT_ID');
        if (empty($projectId)) {
            return null;
        }

        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/firebase/credentials.json'));
            $client->addScope('https://www.googleapis.com/auth/datastore');
            $client->refreshTokenWithAssertion();
            $accessToken = $client->getAccessToken()['access_token'] ?? null;
            if (empty($accessToken)) {
                return null;
            }

            $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}/" . rawurlencode($id);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($result === false || $httpCode !== 200) {
                return null;
            }

            $decoded = json_decode($result, true);
            if (!isset($decoded['fields'])) {
                return null;
            }

            return $this->unwrapFields($decoded['fields']);
        } catch (\Throwable $e) {
            \Log::warning('ShareLinkController fetch failed', ['collection' => $collection, 'id' => $id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Firestore REST wraps every value as {"stringValue": "..."} / {"doubleValue": 1} /
     * etc. -- unwrap the handful of scalar types the share views actually need.
     */
    private function unwrapFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (array_key_exists('stringValue', $value)) {
                $out[$key] = $value['stringValue'];
            } elseif (array_key_exists('integerValue', $value)) {
                $out[$key] = $value['integerValue'];
            } elseif (array_key_exists('doubleValue', $value)) {
                $out[$key] = $value['doubleValue'];
            } elseif (array_key_exists('booleanValue', $value)) {
                $out[$key] = $value['booleanValue'];
            }
        }
        return $out;
    }

    private function notFoundView(string $type)
    {
        return response()->view('share.not-found', ['type' => $type], 404);
    }
}
