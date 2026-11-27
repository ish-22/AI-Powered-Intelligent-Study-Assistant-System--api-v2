<?php

// Prepare writable storage directory in /tmp for Vercel Serverless execution
if (isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || getenv('VERCEL')) {
    $storagePath = '/tmp/storage';
    $subDirs = [
        $storagePath . '/framework/views',
        $storagePath . '/framework/cache/data',
        $storagePath . '/framework/sessions',
        $storagePath . '/logs',
        $storagePath . '/app/public',
    ];

    foreach ($subDirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    $_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;
    putenv("LARAVEL_STORAGE_PATH={$storagePath}");
}

require __DIR__.'/../public/index.php';

