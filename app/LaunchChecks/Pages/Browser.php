<?php

namespace App\LaunchChecks\Pages;

use JsonException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Headless Chromium for the page checks (node/pages.cjs), with Playwright and axe-core pinned in
 * the diagram tool's lock file (tools/diagrams, installed with npm ci --prefix tools/diagrams),
 * as CI installs them. Given an InProcessSite, every request the browser makes is answered by it;
 * without one, the browser goes to the real address over the network.
 */
final readonly class Browser
{
    /** The page checks visit every page several times; give a large help centre time. */
    private const TIMEOUT_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $job  What node/pages.cjs does (its header says).
     * @return array<string, mixed> What it found.
     *
     * @throws BrowserFailed When Chromium couldn't be started or the run stopped.
     */
    public function run(array $job, ?InProcessSite $site = null): array
    {
        $input = new InputStream;
        $process = new Process(
            ['node', __DIR__.'/node/pages.cjs', (string) json_encode([...$job, 'bridged' => $site !== null, 'public' => public_path()], JSON_UNESCAPED_SLASHES)],
            env: ['NODE_PATH' => self::nodePath()],
        );
        $process->setInput($input);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->start();

        $buffer = '';
        $done = null;
        try {
            foreach ($process as $type => $output) {
                if ($type !== Process::OUT) {
                    continue;
                }
                $buffer .= $output;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                    $buffer = substr($buffer, $end + 1);
                    if (! is_array($line)) {
                        continue;
                    }
                    if (isset($line['request']) && is_array($line['request']) && $site !== null) {
                        /** @var array{id: int, method: string, url: string, headers: array<string, string>, body: ?string} $request */
                        $request = $line['request'];
                        $input->write(json_encode(['id' => $request['id'], ...$site->answer($request)])."\n");
                    } elseif (isset($line['done']) && is_array($line['done'])) {
                        $done = $line['done'];
                    }
                }
            }
        } catch (JsonException $unreadable) {
            $process->stop();

            throw new BrowserFailed("the browser said something that isn't JSON: {$unreadable->getMessage()}");
        } finally {
            $input->close();
        }

        if (! is_array($done)) {
            $said = trim($process->getErrorOutput());

            throw new BrowserFailed('headless Chromium stopped before it finished'.($said === '' ? '' : ': '.strtok($said, "\n")));
        }

        /** @var array<string, mixed> $done */
        return $done;
    }

    /**
     * Where Node finds Playwright and axe-core: the diagram tool's own pinned copies first, then
     * NODE_PATH as CI and cloud sessions set it.
     */
    private static function nodePath(): string
    {
        $set = getenv('NODE_PATH');

        return base_path('tools/diagrams/node_modules').(is_string($set) && $set !== '' ? PATH_SEPARATOR.$set : '');
    }
}
