<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'rekognition' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_REKOGNITION_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ably' => [
        'key' => env('ABLY_KEY'),
    ],

    'agora' => [
        'app_id' => env('AGORA_APP_ID'),
        'app_certificate' => env('AGORA_APP_CERTIFICATE'),
    ],

    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

    'learning' => [
        'secret' => env('LEARNING_CLIENT_SECRET'),
    ],

    // Spotify is used for music-sticker search on stories. Credentials are
    // obtained via Client Credentials Flow (server-to-server). When tracks
    // don't have a preview_url, we fall back to iTunes Search for the
    // preview audio. If neither key is set, the music endpoint silently
    // falls back to iTunes-only search.
    'spotify' => [
        'client_id'     => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'market'        => env('SPOTIFY_MARKET', 'MA'),
    ],

    // Public LionsGeek site (lionsgeek.ma). Proxied for the mobile app so a
    // device on the local network can read events / info-sessions through this
    // server instead of needing direct public-internet access. The key is held
    // here server-side and never shipped to the device in proxy mode.
    'lionsgeek' => [
        'url' => env('LIONSGEEK_MA_API_URL', 'https://lionsgeek.ma'),
        'key' => env('LIONSGEEK_MA_API_KEY'),
        // Keep TLS verification on. Set LIONSGEEK_MA_API_VERIFY=false only for
        // local dev where PHP cURL lacks a CA bundle (cURL error 60).
        'verify' => env('LIONSGEEK_MA_API_VERIFY', true),
    ],

    // Apple PushKit VoIP (iOS CallKit cold-start ringing).
    // Create a Key in Apple Developer with Apple Push Notifications enabled,
    // download the .p8, and set APNS_* env vars. Bundle id must match the app
    // (topic becomes {bundle_id}.voip).
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.lionsgeek.lionsgeek-mobile'),
        'key_path' => env('APNS_KEY_PATH'),
        'key_contents' => env('APNS_KEY_CONTENTS'),
        'production' => filter_var(env('APNS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
