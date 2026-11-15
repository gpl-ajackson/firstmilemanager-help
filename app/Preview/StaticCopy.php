<?php

namespace App\Preview;

use App\Site\Http\Controllers\RobotsTxt;
use Dom\HTMLDocument;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The help centre's pages written to a folder as Cloudflare Pages serves them (internal to the
 * Help Preview). Each address is asked of the app in this process, through the same routes and
 * middleware a reader's request takes, and every address a page or stylesheet refers to (links,
 * images, styles, fonts) is followed, so the preview holds every page a reader can reach and
 * everything those pages need to look right.
 *
 * Pages route matching: a page at /articles/x is written as articles/x.html, which Pages serves
 * at /articles/x itself (and redirects /articles/x.html and /articles/x/ there), the help
 * centre's own addresses; the home is index.html. Anything else (a style, a font, a Picture) is
 * written at its address. 404.html is the page not found, which Pages answers for any address it
 * has no file for (without one, Pages would serve the home page for every unknown address).
 * Redirects go into _redirects and response headers into _headers, Pages' own files.
 */
final class StaticCopy
{
    /** Where the app thinks the requests come from. */
    public const ORIGIN = 'http://help-preview.test';

    /** How the preview's requests name themselves ("preview" makes them an obvious bot, too). */
    public const USER_AGENT = 'Stockist Help Preview';

    /** The headers every preview page carries: never indexed, whatever a page says. */
    public const ROBOTS = 'noindex, nofollow';

    /** The app's own security headers carried over to Pages' _headers (#58 may change their values). */
    private const KEPT_HEADERS = [
        'Strict-Transport-Security',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'X-Frame-Options',
        'Permissions-Policy',
        'Content-Security-Policy',
    ];

    /** Fingerprinted addresses, whose files never change, so Pages may let browsers keep them. */
    private const KEPT_FOR_A_YEAR = ['/assets/*', '/pictures/*'];

    /** An address no page has, asked for the page not found. */
    private const NOWHERE = '/help-preview-has-no-page-here';

    /** @var array<string, true> Every address asked for or written. */
    private array $seen = [];

    /** @var list<string> Addresses waiting to be asked for. */
    private array $waiting = [];

    /** @var array<string, array{0: string, 1: int}> from => [to, status] */
    private array $redirects = [];

    /** @var array<string, string> The app's security headers, as it sent them. */
    private array $headers = [];

    private int $pages = 0;

    private int $files = 0;

    private ?Request $before;

    public function __construct(private readonly Kernel $kernel, private readonly string $into)
    {
        $before = app()->bound('request') ? app('request') : null;
        $this->before = $before instanceof Request ? $before : null;
        File::ensureDirectoryExists($into);
    }

    /** Writes a page drawn without a request (the preview's own list of drafts). */
    public function page(string $address, string $html): void
    {
        $this->seen[$address] = true;
        $this->writePage($address, $html);
    }

    /**
     * Asks for each address, and every address the pages and styles found refer to, writing
     * each page and file.
     *
     * @param  list<string>  $addresses
     */
    public function follow(array $addresses): void
    {
        array_push($this->waiting, ...$addresses);
        while (($address = array_shift($this->waiting)) !== null) {
            if (isset($this->seen[$address]) || ! self::isCopied($address)) {
                continue;
            }
            $this->seen[$address] = true;

            $public = public_path(ltrim($address, '/'));
            if ($address !== '/' && is_file($public) && pathinfo($public, PATHINFO_EXTENSION) !== 'php') {
                // A file the web server answers itself (the favicons), never the app.
                $this->writeFile($address, (string) file_get_contents($public));

                continue;
            }

            $this->copy($address);
        }
    }

    /**
     * The content's old addresses, each redirecting for good to where its help is now.
     *
     * @param  array<string, string>  $redirects  from => to
     */
    public function redirects(array $redirects): void
    {
        foreach ($redirects as $from => $to) {
            $this->redirects[$from] ??= [$to, 301];
        }
    }

    /** Writes the page not found, robots.txt, _redirects and _headers, and puts back the app's request. */
    public function finish(): void
    {
        [$status, $html] = $this->ask(self::NOWHERE);
        if ($status === Response::HTTP_NOT_FOUND) {
            $this->pages++;
            $this->writeFile('/404.html', $html);
        }

        $this->writeFile('/robots.txt', "User-agent: *\nDisallow: /\n");

        $lines = [];
        foreach ($this->redirects as $from => [$to, $status]) {
            $lines[] = "{$from} {$to} {$status}";
        }
        $this->writeFile('/_redirects', $lines === [] ? '' : implode("\n", $lines)."\n");

        $headers = "/*\n  X-Robots-Tag: ".self::ROBOTS."\n";
        foreach ($this->headers as $name => $value) {
            $headers .= "  {$name}: {$value}\n";
        }
        foreach (self::KEPT_FOR_A_YEAR as $path) {
            $headers .= "{$path}\n  Cache-Control: public, max-age=31536000, immutable\n";
        }
        $this->writeFile('/_headers', $headers);

        if ($this->before !== null) {
            app()->instance('request', $this->before);
        }
    }

    /** How many pages were written, the page not found included. */
    public function pages(): int
    {
        return $this->pages;
    }

    /** How many files were written in all. */
    public function files(): int
    {
        return $this->files;
    }

    /** Asks the app for one address and writes what it answers. */
    private function copy(string $address): void
    {
        [$status, $body, $response] = $this->ask($address);

        if ($response->isRedirection()) {
            $to = (string) $response->headers->get('Location');
            $this->redirects[$address] ??= [str_starts_with($to, self::ORIGIN) ? (substr($to, strlen(self::ORIGIN)) ?: '/') : $to, $status];

            return;
        }
        // A Retired Article's notice (410) is a page too; Pages answers it with 200.
        if ($status !== Response::HTTP_OK && $status !== Response::HTTP_GONE) {
            return;
        }

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            if ($this->headers === []) {
                foreach (self::KEPT_HEADERS as $name) {
                    if ($response->headers->has($name)) {
                        $this->headers[$name] = (string) $response->headers->get($name);
                    }
                }
            }
            $this->writePage($address, $body);

            return;
        }

        $this->writeFile($address, $body);
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/css')) {
            preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)/', $body, $found);
            $this->refer($found[1]);
        }
    }

    /**
     * The app's answer to a GET for $address: its status, body and response.
     *
     * @return array{0: int, 1: string, 2: Response}
     */
    private function ask(string $address): array
    {
        $request = Request::create(self::ORIGIN.$address, 'GET', server: [
            'HTTP_USER_AGENT' => self::USER_AGENT,
            'HTTP_ACCEPT' => 'text/html,*/*',
        ]);
        $response = $this->kernel->handle($request);
        $body = match (true) {
            $response instanceof BinaryFileResponse => (string) file_get_contents($response->getFile()->getPathname()),
            $response instanceof StreamedResponse => self::streamed($response),
            default => (string) $response->getContent(),
        };
        $this->kernel->terminate($request, $response);

        return [$response->getStatusCode(), $body, $response];
    }

    private function writePage(string $address, string $html): void
    {
        $this->pages++;
        $this->writeFile($address === '/' ? '/index.html' : rtrim($address, '/').'.html', $html);

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $refers = [];
        foreach ($document->querySelectorAll('[href], [src], [srcset]') as $element) {
            foreach (['href', 'src'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $refers[] = (string) $element->getAttribute($attribute);
                }
            }
            if ($element->hasAttribute('srcset')) {
                foreach (explode(',', (string) $element->getAttribute('srcset')) as $candidate) {
                    $refers[] = (string) strtok(trim($candidate), " \t\n");
                }
            }
        }
        $this->refer($refers);
    }

    /**
     * Queues the help centre's own addresses among $refers (root-relative, without any fragment).
     *
     * @param  list<string>  $refers
     */
    private function refer(array $refers): void
    {
        foreach ($refers as $refer) {
            $address = (string) strtok($refer, '#');
            if (str_starts_with($address, '/') && ! str_starts_with($address, '//') && ! isset($this->seen[$address])) {
                $this->waiting[] = $address;
            }
        }
    }

    /**
     * Whether an address is copied: a plain path of the help centre's (the blank search page
     * too), never one with a query (search results), never one that records something (RobotsTxt::CLOSED) and never the
     * indexing files, which name the real address.
     */
    private static function isCopied(string $address): bool
    {
        if (preg_match('#^/[A-Za-z0-9._/-]*$#', $address) !== 1 || preg_match('#(^|/)\.\.?(/|$)#', $address) === 1) {
            return false;
        }
        foreach ([...RobotsTxt::CLOSED, '/sitemap.xml', '/llms.txt', '/robots.txt'] as $closed) {
            if ($address === rtrim($closed, '/') || str_starts_with($address, rtrim($closed, '/').'/')) {
                return false;
            }
        }

        return true;
    }

    private function writeFile(string $address, string $contents): void
    {
        $path = $this->into.$address;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        $this->files++;
    }

    private static function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
