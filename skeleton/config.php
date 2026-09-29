<?php
return [
    'env'     => getenv('KIP_ENV') ?: 'prod', // prod default, D4 info-disclosure rule
    'db'      => ['dsn' => 'sqlite:' . __DIR__ . '/app/data.sqlite'],
    'log_db'  => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
    'app_dir' => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
    'admin'   => ['enabled' => true],   // /admin CRUD panel, gate: users.is_admin = 1
    'uploads' => ['dir' => __DIR__ . '/public/uploads'],
    'base_url' => getenv('KIP_BASE_URL') ?: 'http://localhost:8080',   // absolute links in emails; also pins the OAuth redirect URI
    'mail'     => ['transport' => 'log', 'log_path' => __DIR__ . '/app/mail.log', 'from' => 'noreply@localhost'],
    // "Sign in with a provider" (guide ch. 6). A provider appears on the login
    // page once BOTH its env vars are set; the redirect URI is
    // {base_url}/oauth/callback/{provider} and must match the provider's own
    // registration exactly, so KIP_BASE_URL has to be the site's real URL.
    'oauth' => ['providers' => [
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
    ]],
];
