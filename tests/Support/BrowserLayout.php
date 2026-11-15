<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page laid out in headless Chromium (Playwright, pre-installed in cloud sessions and set up
 * in CI) at one width, with its styles, fonts and images from public/: where each part sits and
 * whether the page scrolls sideways. For tests about layout that HTML alone can't answer.
 *
 *     $layout = BrowserLayout::of($this->get('/articles/x'), width: 390, selectors: ['.article', '.related']);
 *     $layout->box('.related')['y']
 */
final readonly class BrowserLayout
{
    /**
     * @param  array<string, array{x: float, y: float, width: float, height: float}|null>  $boxes
     */
    private function __construct(
        public int $width,
        public int $scrollWidth,
        private array $boxes,
    ) {}

    /**
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $selectors
     */
    public static function of(TestResponse $response, int $width, array $selectors): self
    {
        $response->assertOk();
        $html = tempnam(sys_get_temp_dir(), 'help-page-');
        File::put($html, (string) $response->getContent());

        try {
            $result = Process::timeout(60)->run(['node', __DIR__.'/browser/layout.cjs', (string) json_encode([
                'html' => $html,
                'public' => public_path(),
                'pictures' => config('help.served_pictures_path'),
                'width' => $width,
                'selectors' => $selectors,
            ])]);
        } finally {
            File::delete($html);
        }

        Assert::assertTrue($result->successful(), "Chromium could not lay out the page:\n".$result->errorOutput());
        $layout = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new self($layout['viewportWidth'], $layout['scrollWidth'], $layout['boxes']);
    }

    /**
     * Where the first element matching $selector sits on the page; fails when there is none.
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function box(string $selector): array
    {
        $box = $this->boxes[$selector] ?? null;
        Assert::assertNotNull($box, "The page has no {$selector}");

        return $box;
    }
}
