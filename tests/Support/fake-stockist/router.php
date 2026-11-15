<?php

/*
 * A fake Stockist for the picture tool's fast tests (Tests\Support\FakeStockist), served by PHP's
 * built-in server. It has just what the camera meets in the real one: a sign-in screen, a top
 * bar (the navigation named "Topbar", painted pure red so a test can see whether a Picture kept
 * it) holding the logo, the team's tenant menu and the menu items, and Team Settings, where the
 * team's owner sets its name. Pages are written by the test; "{team}" in a page shows the team's
 * current name. State lives in files in FAKE_STOCKIST_STATE, so it outlasts each request.
 */

$state = (string) getenv('FAKE_STOCKIST_STATE');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$team = (string) file_get_contents("{$state}/team.txt");
/** @var array<string, string> $pages menu item => page body */
$pages = json_decode((string) file_get_contents("{$state}/pages.json"), true);
$who = $_COOKIE['who'] ?? '';

$e = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
$address = fn (string $menuItem): string => '/'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($menuItem)), '-');

$layout = function (string $title, string $body) use ($team, $pages, $e, $address, $state): string {
    $menu = '';
    foreach (array_keys($pages) as $menuItem) {
        $menu .= '<a href="'.$address($menuItem).'">'.$e($menuItem).'</a> ';
    }
    $teamSettings = is_file("{$state}/no-team-settings") ? '' : '<a href="/team">Team Settings</a>';
    $body = str_replace('{team}', $e($team), $body);

    return <<<HTML
        <!doctype html>
        <html lang="en-GB">
        <head>
        <meta charset="utf-8">
        <title>{$e($title)}</title>
        <style>
            body { margin: 0; font: 16px/1.5 sans-serif; color: #111; background: #fff; }
            .top { position: sticky; top: 0; background: #ff0000; }
            .top nav { height: 64px; color: #fff; display: flex; gap: 16px; align-items: center; padding: 0 16px; }
            .top a, .top button { color: #fff; }
            .tenant-items { position: absolute; top: 56px; background: #fff; padding: 8px; border: 1px solid #ccc; }
            .tenant-items a { color: #111; }
            main { padding: 24px; }
            section { margin: 24px 0; padding: 16px; border: 1px solid #ccc; }
            .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        </style>
        </head>
        <body>
        <div class="top">
            <nav aria-label="Topbar">
                <img alt="Stockist logo" width="96" height="24" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='96' height='24'/%3E">
                <strong>Stockist WMS</strong>
                <span>
                    <button type="button" onclick="document.querySelector('.tenant-items').hidden = false">{$e($team)}</button>
                    <span class="tenant-items" hidden>{$teamSettings}</span>
                </span>
                {$menu}
            </nav>
        </div>
        <main>{$body}</main>
        </body>
        </html>
        HTML;
};

if ($path === '/login' && $method === 'POST') {
    setcookie('who', (string) ($_POST['email'] ?? ''), ['path' => '/']);
    $first = array_key_first($pages);
    header('Location: '.($first === null ? '/' : $address((string) $first)), true, 302);

    return true;
}

if ($path === '/login') {
    echo <<<'HTML'
        <!doctype html>
        <html lang="en-GB"><head><meta charset="utf-8"><title>Sign in</title></head>
        <body><main>
        <h1>Sign in</h1>
        <form method="post" action="/login">
            <label for="email">Email address</label> <input id="email" name="email" type="email">
            <label for="password">Password</label> <input id="password" name="password" type="password">
            <button type="submit">Sign in</button>
        </form>
        </main></body></html>
        HTML;

    return true;
}

if ($path === '/team' && $method === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    file_put_contents("{$state}/team.txt", $name);
    file_put_contents("{$state}/renames.log", json_encode(['by' => $who, 'name' => $name])."\n", FILE_APPEND);
    header('Location: /team?saved=1', true, 302);

    return true;
}

if ($path === '/team' && ! is_file("{$state}/no-team-settings")) {
    $saved = isset($_GET['saved']) ? '<p role="status">Saved</p>' : '';
    echo $layout('Team Settings', <<<HTML
        <h1>Team Settings</h1>
        {$saved}
        <section>
            <h2>Team Name</h2>
            <form method="post" action="/team">
                <label for="team-name">Team Name</label> <input id="team-name" name="name" value="{team}">
                <button type="submit">Save</button>
            </form>
        </section>
        <section>
            <h2>Update Password</h2>
            <label for="password">Password</label> <input id="password" type="password">
            <button type="button">Save</button>
        </section>
        HTML);

    return true;
}

foreach ($pages as $menuItem => $body) {
    if ($path === $address($menuItem)) {
        if (is_file("{$state}/slow-".md5($menuItem))) {
            usleep(1000 * (int) file_get_contents("{$state}/slow-".md5($menuItem)));
        }
        echo $layout($menuItem, $body);

        return true;
    }
}

http_response_code(404);
echo $layout('Not found', '<h1>Not found</h1>');

return true;
