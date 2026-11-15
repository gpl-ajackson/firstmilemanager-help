<?php

/*
 * A fake of Cloudflare's Pages deployments API, for the Help Preview workflow's removal step
 * (tests/Feature/Preview/HelpPreviewWorkflowTest.php). It answers
 *     GET    /client/v4/accounts/<account>/pages/projects/<project>/deployments?env=preview&page=&per_page=
 *     DELETE /client/v4/accounts/<account>/pages/projects/<project>/deployments/<id>?force=true
 * from deployments.json in FAKE_CLOUDFLARE_STATE, logging each request to requests.log. Every
 * request must carry "Authorization: Bearer <token.txt>".
 */

$state = (string) getenv('FAKE_CLOUDFLARE_STATE');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
file_put_contents("{$state}/requests.log", "{$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}\n", FILE_APPEND);
header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer '.file_get_contents("{$state}/token.txt")) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => [['message' => 'Authentication error']]]);

    return;
}
if (preg_match('#^/client/v4/accounts/([^/]+)/pages/projects/([^/]+)/deployments(?:/([^/]+))?$#', $path, $match) !== 1) {
    http_response_code(404);
    echo json_encode(['success' => false]);

    return;
}

/** @var list<array<string, mixed>> $deployments */
$deployments = json_decode((string) file_get_contents("{$state}/deployments.json"), true);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($match[3])) {
    if (($query['force'] ?? null) !== 'true') {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => [['message' => 'Cannot delete an aliased deployment without force']]]);

        return;
    }
    $deployments = array_values(array_filter($deployments, fn (array $deployment): bool => $deployment['id'] !== $match[3]));
    file_put_contents("{$state}/deployments.json", json_encode($deployments));
    echo json_encode(['success' => true, 'result' => null]);

    return;
}

$perPage = (int) ($query['per_page'] ?? 25);
$page = (int) ($query['page'] ?? 1);
echo json_encode([
    'success' => true,
    'result' => array_slice($deployments, ($page - 1) * $perPage, $perPage),
    'result_info' => ['page' => $page, 'per_page' => $perPage, 'total_count' => count($deployments), 'total_pages' => max(1, (int) ceil(count($deployments) / $perPage))],
]);
