<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        setcookie('XSRF-TOKEN-AK', bin2hex(env('FIREBASE_APIKEY')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-AD', bin2hex(env('FIREBASE_AUTH_DOMAIN')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-DU', bin2hex(env('FIREBASE_DATABASE_URL')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-PI', bin2hex(env('FIREBASE_PROJECT_ID')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-SB', bin2hex(env('FIREBASE_STORAGE_BUCKET')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-MS', bin2hex(env('FIREBASE_MESSAAGING_SENDER_ID')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-AI', bin2hex(env('FIREBASE_APP_ID')), time() + 3600, "/"); 
        setcookie('XSRF-TOKEN-MI', bin2hex(env('FIREBASE_MEASUREMENT_ID')), time() + 3600, "/"); 
        // Prefer env; fall back to known public Web Push key pair (safe to embed — NOT the private key)
        $firebaseVapidKey = env('FIREBASE_VAPID_KEY') ?: 'BOtZszHqje_L4gzcsxWnthkH03b3zWLyH6_a-a8TEgQB-6o4J2aAQEif3ermwymDYvhcz2r-u-JvhdfVz1FMnFQ';
        if ($firebaseVapidKey) {
            setcookie('XSRF-TOKEN-VK', bin2hex($firebaseVapidKey), time() + 3600, "/");
        }

        $countries_data = [];
        $get_countries_json = file_get_contents(public_path('countriesdata.json'));
        if($get_countries_json != ''){
            $countries_data = json_decode($get_countries_json);
        }
        view()->composer('*', function($view) use($countries_data, $firebaseVapidKey) {
            $view->with('countries_data', $countries_data);
            $view->with('firebase_vapid_key', $firebaseVapidKey);
            $view->with('admin_auth_user', auth()->check() ? [
                'id' => (string) auth()->id(),
                'email' => auth()->user()->email ?? '',
                'name' => auth()->user()->name ?? '',
            ] : null);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Force HTTPS on the real deployment only -- local dev over plain http
        // (APP_ENV=local, the .env.example default) stays unaffected.
        if (!$this->app->environment('local')) {
            URL::forceScheme('https');
        }
    }
}
