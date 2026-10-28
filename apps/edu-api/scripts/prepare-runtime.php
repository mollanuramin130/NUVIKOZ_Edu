<?php

foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/framework/sessions', 'storage/framework/testing', 'storage/framework/views', 'storage/logs'] as $directory) {
    $path = dirname(__DIR__).'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Cannot create private runtime directory.');
    }
}
