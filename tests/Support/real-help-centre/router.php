<?php

/*
 * The help centre at its real address behind Cloudflare Access, as Launch Check 11 meets it
 * before going public (RealHelpCentre.php runs it with PHP's built-in server). A request
 * without Access's service token headers is sent to Access's sign-in page on another host
 * (localhost); with them, every address answers a small page (/no-such-page answers 404). Each
 * token it is sent is written to REAL_HELP_CENTRE_LOG, one line per request.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$page = static fn (string $title): string => "<!DOCTYPE html><html lang=\"en-GB\"><head><meta charset=\"utf-8\"><title>{$title}</title></head>"
    ."<body><main><h1>{$title}</h1><p>Scan each pallet as it comes off the lorry.</p></main></body></html>";

if (str_starts_with($path, '/cdn-cgi/access/login')) {
    echo $page('Sign in with Cloudflare Access');

    return true;
}

$id = $_SERVER['HTTP_CF_ACCESS_CLIENT_ID'] ?? null;
$secret = $_SERVER['HTTP_CF_ACCESS_CLIENT_SECRET'] ?? null;
if ($id === null || $secret === null) {
    header('Location: http://localhost:'.$_SERVER['SERVER_PORT'].'/cdn-cgi/access/login?redirect_url='.rawurlencode($path), true, 302);

    return true;
}

file_put_contents((string) getenv('REAL_HELP_CENTRE_LOG'), "{$id} {$secret} {$path}\n", FILE_APPEND);
if ($path === '/no-such-page') {
    http_response_code(404);
    echo $page('Page not found');

    return true;
}
echo $page('Help centre');

return true;
