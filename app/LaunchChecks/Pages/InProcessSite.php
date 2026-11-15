<?php

namespace App\LaunchChecks\Pages;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The help centre answering a browser's requests inside this process, through the same HTTP
 * kernel, routes and middleware a deploy serves, so the browser sees the content under test that
 * the page checks loaded into the database in a transaction they roll back afterwards. Nothing
 * listens on a port and nothing is written for good.
 */
final class InProcessSite
{
    /** Where the browser thinks the help centre is. */
    public const HOST = 'help.test';

    public const ORIGIN = 'http://'.self::HOST;

    private ?Request $before;

    public function __construct(
        private readonly Application $app,
        private readonly Kernel $kernel,
        /** The reader's network address, as the request's REMOTE_ADDR. */
        private readonly string $readerAddress = '127.0.0.1',
    ) {
        $before = $app->bound('request') ? $app->make('request') : null;
        $this->before = $before instanceof Request ? $before : null;
    }

    /**
     * @param  array{method: string, url: string, headers: array<string, string>, body: ?string}  $request  As the browser script asks (body base64).
     * @return array{status: int, headers: array<string, string>, body: string} The answer, body base64.
     */
    public function answer(array $request): array
    {
        $server = ['REMOTE_ADDR' => $this->readerAddress];
        foreach ($request['headers'] as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }
        $content = $request['body'] === null ? null : (string) base64_decode($request['body']);
        $parameters = [];
        if ($content !== null && str_starts_with($request['headers']['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($content, $parameters);
        }

        $httpRequest = Request::create($request['url'], $request['method'], $parameters, [], [], $server, $content);
        $response = $this->kernel->handle($httpRequest);

        $body = match (true) {
            $response instanceof BinaryFileResponse => (string) file_get_contents($response->getFile()->getPathname()),
            $response instanceof StreamedResponse => self::streamed($response),
            default => (string) $response->getContent(),
        };
        $headers = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_map(strval(...), array_filter($values, is_scalar(...))));
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'] = (string) $cookie;
        }
        $this->kernel->terminate($httpRequest, $response);

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => base64_encode($body)];
    }

    /** Puts back the request the app had before the browser's, so later URLs are built as before. */
    public function close(): void
    {
        if ($this->before !== null) {
            $this->app->instance('request', $this->before);
        }
    }

    private static function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
