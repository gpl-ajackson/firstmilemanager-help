<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A reader in headless Chromium (Playwright, as BrowserLayout) with script on, typing into the
 * search box while the page's clock is held, so "two seconds idle" is a step, not a wait. Every
 * request the page makes, its script's included, goes through the test's app, so it sees the
 * fixture content the test published and its records land in the test's database.
 *
 *     $reader = SearchingReader::visit($this, '/search', [['type', 'pal'], ['idle', 2000]]);
 *     $reader->requests; $reader->page()->texts('.suggestions a'); $reader->url
 *
 * Steps: ['type', '<text>'], ['press', '<key>'], ['idle', <milliseconds>], ['click', '<button
 * name>'], ['open', '<words in a link>'] (a suggestion's, if one has them), ['see', '<words>'].
 * After each one it waits until the page asks for nothing more. The page's clock stands still
 * between steps.
 */
final readonly class SearchingReader
{
    /**
     * @param  list<string>  $requests  Every request the app answered, as "<METHOD> <path and query>", in order.
     * @param  array{local: int, session: int, indexedDb: int}  $storage  What the page keeps on the device at the end.
     */
    private function __construct(
        public string $url,
        /** How wide the page is laid out, in CSS pixels: wider than the screen means sideways scrolling. */
        public int $scrollWidth,
        public string $html,
        public int $cookies,
        public array $storage,
        public array $requests,
    ) {}

    /**
     * @param  list<array{0: string, 1: string|int}>  $steps
     * @param  int  $width  The screen's width in CSS pixels.
     */
    public static function visit(TestCase $test, string $open, array $steps = [], int $width = 390): self
    {
        $input = new InputStream;
        $process = new Process(['node', __DIR__.'/browser/searching-reader.cjs', (string) json_encode([
            'public' => public_path(),
            // A reader's browser, not headless Chromium's own user agent, which counts as a bot.
            'userAgent' => A_READER,
            'open' => $open,
            'steps' => $steps,
            'width' => $width,
            // HELP_SCREENSHOTS=<folder> saves the page as the test leaves it, to look at by eye.
            'screenshot' => is_string(getenv('HELP_SCREENSHOTS')) ? getenv('HELP_SCREENSHOTS').'/'.preg_replace('/\W+/', '-', $open).'-'.$width.'-'.substr(md5((string) json_encode($steps)), 0, 6).'.png' : null,
        ])]);
        $process->setInput($input);
        $process->setTimeout(90);
        $process->start();

        $buffer = '';
        $done = null;
        $requests = [];
        $errors = '';
        foreach ($process as $type => $output) {
            if ($type !== Process::OUT) {
                $errors .= $output;

                continue;
            }
            $buffer .= $output;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                $buffer = substr($buffer, $end + 1);
                if (isset($line['request'])) {
                    $request = $line['request'];
                    $query = parse_url($request['url'], PHP_URL_QUERY);
                    $requests[] = $request['method'].' '.parse_url($request['url'], PHP_URL_PATH).(is_string($query) ? '?'.$query : '');
                    $input->write(json_encode(['id' => $request['id'], ...self::answer($test, $request)])."\n");
                } elseif (isset($line['done'])) {
                    $done = $line['done'];
                }
            }
        }
        $input->close();

        Assert::assertNotNull($done, "The browser didn't finish:\n".$errors);

        return new self($done['url'], $done['scrollWidth'], $done['html'], $done['cookies'], $done['storage'], $requests);
    }

    /** The page as the browser shows it at the end, its script's changes included. */
    public function page(): ReaderPage
    {
        return ReaderPage::ofHtml($this->html);
    }

    /**
     * The requests whose "<METHOD> <path>" starts with $start.
     *
     * @return list<string>
     */
    public function requestsTo(string $start): array
    {
        return array_values(array_filter($this->requests, fn (string $request): bool => str_starts_with($request, $start)));
    }

    /**
     * @param  array{method: string, url: string, headers: array<string, string>, body: ?string}  $request
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function answer(TestCase $test, array $request): array
    {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }
        $content = $request['body'] === null ? null : (string) base64_decode($request['body']);
        $parameters = [];
        if ($content !== null && str_starts_with($request['headers']['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($content, $parameters);
        }

        /** @var TestResponse<Response> $response */
        $response = $test->call($request['method'], $request['url'], $parameters, [], [], $server, $content);
        $headers = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'] = (string) $cookie;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => base64_encode((string) $response->getContent()),
        ];
    }
}
