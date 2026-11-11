<?php

/** Local development router; production should point its web root at public/. */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$public = realpath(__DIR__.'/public');
$file = realpath($public.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require_once __DIR__.'/public/index.php';
