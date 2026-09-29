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
    'app_dir' => __DIR__ . '/app',
    'trusted_proxy' => (bool) getenv('KIP_TRUSTED_PROXY'),
    'admin'   => ['enabled' => true],   // /admin CRUD panel, gate: users.is_admin = 1
    'uploads' => ['dir' => __DIR__ . '/public/uploads'],
    'base_url' => getenv('KIP_BASE_URL') ?: 'http://localhost:8080',   // absolute links in emails
    'mail'     => ['transport' => 'log', 'log_path' => $dataDir . '/mail.log', 'from' => 'noreply@localhost'],
];
