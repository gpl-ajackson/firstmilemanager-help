<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A reader in headless Chromium (Playwright, as BrowserLayout), with script on or off, going
 * through the app under test: every request the page makes, its own script's included, is sent
 * through the test's app, so it sees the fixture content the test published. Files in public/
 * are served from disk. For tests about what a reader can do in a real browser.
 *
 *     $visit = ReaderBrowser::visit($this, '/articles/x', script: false, steps: [['click', 'No'], ['see', 'What was wrong?']]);
 *     $visit->page()->text('.feedback-title'); $visit->url; $visit->requests
 *
 * Steps: ['click', '<button name>'], ['choose', '<radio label>'], ['fill', '<text box label>',
 * '<text>'], ['see', '<words>'] (waits until the words show). `lose: ['POST /feedback/x']` drops
 * the connection after the app has answered that request the first time.
 */
final readonly class ReaderBrowser
{
    /**
     * @param  list<string>  $requests  Every request the app answered, as "<METHOD> <path>", in order.
     * @param  array{local: int, session: int}|null  $storage  What the page keeps in local and session storage (null with script off).
     */
    private function __construct(
        public string $url,
        public string $html,
        public int $cookies,
        public ?array $storage,
        public array $requests,
    ) {}

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $steps
     * @param  list<string>  $lose  Requests, as "<METHOD> <path>", whose first answer is lost on its way
     *                              back: the app answers (and records), then the browser's connection drops.
     */
    public static function visit(TestCase $test, string $open, bool $script, array $steps = [], array $lose = []): self
    {
        $input = new InputStream;
        $process = new Process(['node', __DIR__.'/browser/reader.cjs', (string) json_encode([
            'public' => public_path(),
            // A reader's browser, not headless Chromium's own user agent, which counts as a bot.
            'userAgent' => A_READER,
            'script' => $script,
            'open' => $open,
            'steps' => $steps,
            'lose' => $lose,
        ])]);
        $process->setInput($input);
        $process->setTimeout(90);
        $process->start();

        $buffer = '';
        $done = null;
        $requests = [];
        foreach ($process as $type => $output) {
            if ($type !== Process::OUT) {
                continue;
            }
            $buffer .= $output;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                $buffer = substr($buffer, $end + 1);
                if (isset($line['request'])) {
                    $request = $line['request'];
                    $requests[] = $request['method'].' '.parse_url($request['url'], PHP_URL_PATH);
                    $input->write(json_encode(['id' => $request['id'], ...self::answer($test, $request)])."\n");
                } elseif (isset($line['done'])) {
                    $done = $line['done'];
                }
            }
        }
        $input->close();

        Assert::assertNotNull($done, "The browser didn't finish:\n".$process->getErrorOutput());

        return new self($done['url'], $done['html'], $done['cookies'], $done['storage'], $requests);
    }

    /** The page as the browser shows it at the end, its script's changes included. */
    public function page(): ReaderPage
    {
        return ReaderPage::ofHtml($this->html);
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
