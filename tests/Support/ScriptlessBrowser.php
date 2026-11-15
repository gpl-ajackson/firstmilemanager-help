<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reader with script turned off, in headless Chromium (Playwright, as BrowserLayout): types
 * into a page's search box, presses Search, and gives back the request the browser made, for
 * the test to send through the app.
 *
 *     $request = ScriptlessBrowser::submitSearch($this->get('/no-such-page'), 'palet');
 *     $this->get($request)
 */
final class ScriptlessBrowser
{
    /**
     * The address the page's search form asked for, by GET; fails the test for any other method.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function submitSearch(TestResponse $response, string $words): string
    {
        $html = tempnam(sys_get_temp_dir(), 'help-page-');
        File::put($html, (string) $response->getContent());

        try {
            $result = Process::timeout(60)->run(['node', __DIR__.'/browser/submit-search.cjs', (string) json_encode([
                'html' => $html,
                'public' => public_path(),
                'words' => $words,
            ])]);
        } finally {
            File::delete($html);
        }

        Assert::assertTrue($result->successful(), "Chromium could not submit the search form:\n".$result->errorOutput());
        $submitted = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertSame('GET', $submitted['method'], 'The search form must work as a plain GET');

        return $submitted['requested'];
    }
}
