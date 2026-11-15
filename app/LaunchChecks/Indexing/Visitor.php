<?php

namespace App\LaunchChecks\Indexing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Asks the help centre for its pages in this process, as a search engine would over HTTP:
 * every request goes through the same routes and middleware a reader's does. Each address is
 * asked once.
 */
final class Visitor
{
    /** How the checks' requests name themselves. */
    public const USER_AGENT = 'Stockist help centre Launch Checks';

    /** @var array<string, Visit> */
    private array $visits = [];

    public function __construct(private readonly Kernel $kernel) {}

    /** What the help centre answers for an address such as "/articles/x" or "/search?q=stock". */
    public function visit(string $address): Visit
    {
        if (isset($this->visits[$address])) {
            return $this->visits[$address];
        }

        $request = Request::create($address, 'GET', server: ['HTTP_USER_AGENT' => self::USER_AGENT, 'HTTP_ACCEPT' => 'text/html']);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        $content = $response->getContent();

        return $this->visits[$address] = new Visit(
            address: $address,
            status: $response->getStatusCode(),
            contentType: (string) $response->headers->get('Content-Type'),
            body: $content === false ? '' : $content,
            robotsHeaderSaysNoindex: str_contains(strtolower(implode(',', $response->headers->all('X-Robots-Tag'))), 'noindex'),
            location: $response->headers->get('Location'),
        );
    }
}
