<?php
// KIP_DATA_DIR repoints the three SQLite databases at one writable directory
// outside the app: required when the app runs from an embedded, read-only
// artifact (kip build), and convenient under containers. Unset, everything
// stays in app/ beside this file.
$dataDir = getenv('KIP_DATA_DIR') ?: __DIR__ . '/app';
return [
    'env'     => getenv('KIP_ENV') ?: 'prod', // prod default, D4 info-disclosure rule
    'db'      => ['dsn' => 'sqlite:' . $dataDir . '/data.sqlite'],
    'log_db'  => ['dsn' => 'sqlite:' . $dataDir . '/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . $dataDir . '/cache.sqlite', 'ttl_seconds' => 3600],
    'admin'   => ['enabled' => true], // /admin panel; gate: users.is_admin = 1 (migration 011, guide chapter 12)
    // Guest comments have no session, so their flood cap is the fixed-window
    // limiter (guide chapter 6): 30 POSTs per minute per address under
    // /comments, counted before routing, past-cap answered 429.
    'rate_limit' => ['comments' => ['max' => 30, 'window' => 60], 'oauth' => ['max' => 20, 'window' => 60]], // oauth: the cross-site-admitted callback POST
    'base_url' => getenv('KIP_BASE_URL') ?: 'http://localhost:8080',   // absolute links in emails; oauth:check needs it to build redirect URIs
    'app_dir' => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
    // The session cookie's SameSite attribute: Lax (default), Strict, or None.
    // None forces the Secure flag; hosts enabling Sign in with Apple need it
    // because Apple's form_post callback is a cross-site POST.
    'session_cookie_samesite' => getenv('KIP_SESSION_SAMESITE') ?: 'Lax',
    // "Sign in with a provider" (guide ch. 6). A provider's button appears on
    // the login page only while its credentials are configured AND pass the
    // readiness probe (Kip\Auth\OAuthHealth); 'probe' => false trades the live
    // check for config-only gating. The redirect URI is
    // {base_url}/oauth/callback/{provider} and must match the provider's own
    // registration exactly, so KIP_BASE_URL has to be the site's real URL.
    'oauth' => ['probe' => true, 'providers' => [
        'google' => [
            'client_id' => getenv('KIP_OAUTH_GOOGLE_CLIENT_ID') ?: null,
            'client_secret' => getenv('KIP_OAUTH_GOOGLE_CLIENT_SECRET') ?: null,
        ],
        'github' => [
            'client_id' => getenv('KIP_OAUTH_GITHUB_CLIENT_ID') ?: null,
            'client_secret' => getenv('KIP_OAUTH_GITHUB_CLIENT_SECRET') ?: null,
        ],
        'microsoft' => [
            'client_id' => getenv('KIP_OAUTH_MICROSOFT_CLIENT_ID') ?: null,
            'client_secret' => getenv('KIP_OAUTH_MICROSOFT_CLIENT_SECRET') ?: null,
        ],
        'discord' => [
            'client_id' => getenv('KIP_OAUTH_DISCORD_CLIENT_ID') ?: null,
            'client_secret' => getenv('KIP_OAUTH_DISCORD_CLIENT_SECRET') ?: null,
        ],
        'facebook' => [
            'client_id' => getenv('KIP_OAUTH_FACEBOOK_CLIENT_ID') ?: null,
            'client_secret' => getenv('KIP_OAUTH_FACEBOOK_CLIENT_SECRET') ?: null,
        ],
        'apple' => [
            'client_id' => getenv('KIP_OAUTH_APPLE_CLIENT_ID') ?: null,      // the Services ID
            'apple_team_id' => getenv('KIP_OAUTH_APPLE_TEAM_ID') ?: null,
            'apple_key_id' => getenv('KIP_OAUTH_APPLE_KEY_ID') ?: null,
            'apple_key_file' => getenv('KIP_OAUTH_APPLE_KEY_FILE') ?: null,  // the .p8, chmod 600
        ],
    ]],
];
