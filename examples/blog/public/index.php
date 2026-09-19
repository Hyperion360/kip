<?php // public/index.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
$config['views'] = $config['app_dir'] . '/views';

ob_start(); // lazy session may start mid-render; nothing may flush before headers

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($config['trusted_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$app->handle(Kip\Http\Request::fromGlobals(trustedProxy: $config['trusted_proxy']))->send();
ob_end_flush();
