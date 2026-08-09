<?php

namespace App\Http\Controllers;

class FirebaseMessagingController extends Controller
{
    /**
     * Serve the Firebase messaging service worker with project config injected.
     * Must be at site root scope for FCM to register successfully.
     */
    public function serviceWorker()
    {
        $config = [
            'apiKey' => env('FIREBASE_APIKEY'),
            'authDomain' => env('FIREBASE_AUTH_DOMAIN'),
            'databaseURL' => env('FIREBASE_DATABASE_URL'),
            'projectId' => env('FIREBASE_PROJECT_ID'),
            'storageBucket' => env('FIREBASE_STORAGE_BUCKET'),
            'messagingSenderId' => env('FIREBASE_MESSAAGING_SENDER_ID'),
            'appId' => env('FIREBASE_APP_ID'),
            'measurementId' => env('FIREBASE_MEASUREMENT_ID'),
        ];

        // Fail loudly in console if production .env firebase keys are blank
        $missing = [];
        foreach (['apiKey', 'projectId', 'messagingSenderId', 'appId'] as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        $js = view('firebase-messaging-sw', [
            'firebaseConfig' => json_encode($config),
            'missingKeys' => $missing,
        ])->render();

        return response($js, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
