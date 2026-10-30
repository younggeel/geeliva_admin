<?php

namespace App\Http\Controllers;

use App\Support\FirestoreCountQuery;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Counts Firestore documents with server-side aggregation queries, so the
 * dashboards no longer download whole collections just to show a number.
 * Firestore bills one read per 1,000 counted index entries.
 */
class DashboardCountController extends Controller
{
    private const MAX_QUERIES = 40;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function count(Request $request)
    {
        $queries = $request->input('queries');
        if (!is_array($queries) || count($queries) === 0 || count($queries) > self::MAX_QUERIES) {
            return response()->json(['success' => false, 'message' => 'Invalid queries'], 422);
        }

        try {
            $bodies = array_map([FirestoreCountQuery::class, 'body'], $queries);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $credentials = json_decode(Storage::disk('local')->get('firebase/credentials.json'), true);
        $url = 'https://firestore.googleapis.com/v1/projects/' . $credentials['project_id']
            . '/databases/(default)/documents:runAggregationQuery';
        $http = new HttpClient(['timeout' => 20]);
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken($credentials)];

        $promises = array_map(fn ($body) => $http->postAsync($url, ['headers' => $headers, 'json' => $body]), $bodies);
        $counts = array_map(
            fn ($response) => FirestoreCountQuery::result((string) $response->getBody()),
            Utils::unwrap($promises)
        );

        return response()->json(['success' => true, 'counts' => $counts]);
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('firestore_count_access_token', 3000, function () use ($credentials) {
            $client = new GoogleClient();
            $client->setAuthConfig($credentials);
            $client->addScope('https://www.googleapis.com/auth/datastore');
            $token = $client->fetchAccessTokenWithAssertion();
            if (empty($token['access_token'])) {
                throw new \RuntimeException('Could not obtain a Firestore access token');
            }
            return $token['access_token'];
        });
    }
}
