<?php

/*
 * Stable link redirects to the new URL.
 *
 * The redirect is relative, so the Host header (which a client controls) is never copied into the response.
 */

$uri = str_replace('stable.php', 'index.php/item#summary', $_SERVER['REQUEST_URI']);

// Only a local path may be sent back, never a scheme-relative "//host" that a browser would treat as another site.
$uri = '/' . ltrim($uri, '/');

http_response_code(307);
header('Location: ' . $uri);
