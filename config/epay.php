<?php

$platformPrivateKeyPath = base_path(false) . DIRECTORY_SEPARATOR . 'epay-platform-private.pem';
$platformPublicKeyPath = base_path(false) . DIRECTORY_SEPARATOR . 'epay-platform-public.pem';

return [
    'charset' => 'UTF-8',
    'v1' => [
        'sign_type' => 'MD5',
    ],
    'v2' => [
        'sign_type' => 'RSA',
        'timestamp_ttl' => 300,
        'transfer_rate' => '0.01',
        'platform_private_key' => is_file($platformPrivateKeyPath) ? trim((string) file_get_contents($platformPrivateKeyPath)) : '',
        'platform_public_key' => is_file($platformPublicKeyPath) ? trim((string) file_get_contents($platformPublicKeyPath)) : '',
    ],
];
