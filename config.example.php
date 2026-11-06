<?php
declare(strict_types=1);

/**
 * Copy to config.php outside the web document root.
 * Never place config.php or storage/ under the publicly accessible directory.
 */
return [
    'app' => [
        'name' => 'Post Comment',
        'timezone' => 'Asia/Tokyo',
        'debug' => false,
    ],
    'url' => [
        'base_url' => null, // e.g. https://example.org
        'base_path' => null, // e.g. /post-comment (without trailing slash)
    ],
    'storage' => [
        'database_path' => __DIR__ . '/storage/database.sqlite',
        'backup_path' => __DIR__ . '/storage/backups',
    ],
    'session' => [
        'name' => 'post_comment_session',
        'secure_cookie' => true,
    ],
    'installation' => [
        // Set a unique, random 32+ character secret before first setup.
        'setup_key' => '',
    ],
    'update' => [
        'repository' => 'Plana-ria/post-comment',
        'auto_apply' => false,
        // 64 hexadecimal characters of the trusted Ed25519 public key.
        // Leave blank to keep signed update installation disabled.
        'public_key' => '',
    ],
];
