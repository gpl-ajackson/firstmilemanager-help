<?php

use Tests\Support\BrowserLayout;
use Tests\Support\ReaderPage;

/*
 * Spec #30 user stories 26, 37 and 94, ADR-0005: an Article shows its Pictures from the
 * pictures folder by Picture Name, each under its step, with the alt text from its Picture
 * Recipe, served at the size the page needs from the double-density file the picture tool took.
 */

const STEPS_WITH_PICTURES = <<<'MD'
    ## Steps

    1. Go to **Inbound › Inbound Shipments** and open the shipment.

       ![](picture:inbound-shipments-open-a-shipment)

    2. Scan `NEW-PAL` on the Dock Receiving Terminal.

       ![](picture:dock-receiving-terminal-new-pallet)

    3. Select **Complete receiving**.
    MD;

beforeEach(function () {
    $this->content = content()
        ->picture('inbound-shipments-open-a-shipment', [
            'alt' => 'The Inbound Shipments list with the shipment from Harbour Foods outlined.',
            'device' => 'desk',
        ], height: 1600)
        ->picture('dock-receiving-terminal-new-pallet', [
            'alt' => 'The Dock Receiving Terminal asking for the first product on a new pallet.',
            'device' => 'handheld',
        ], height: 1280)
        ->article('receive-at-the-dock', [], STEPS_WITH_PICTURES)
        ->publish();
});

it('shows each Picture under its step with the alt text from its Recipe', function () {
    $page = ReaderPage::of($this->get('/articles/receive-at-the-dock'));

    expect($page->attributes('.prose ol > li:nth-child(1) img'))->toHaveCount(1)
        ->and($page->attributes('.prose ol > li:nth-child(1) img')[0]['alt'])
        ->toBe('The Inbound Shipments list with the shipment from Harbour Foods outlined.')
        ->and($page->attributes('.prose ol > li:nth-child(2) img')[0]['alt'])
        ->toBe('The Dock Receiving Terminal asking for the first product on a new pallet.')
        ->and($page->attributes('.prose ol > li:nth-child(3) img'))->toBe([]);
});

it('serves a desk Picture at the size the page needs from its double-density file', function () {
    $picture = ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('img.picture-desk')[0];

    // The tool takes desk Pictures 2560 wide, double density: 1280 CSS pixels.
    expect($picture['width'])->toBe('1280')
        ->and($picture['height'])->toBe('800')
        ->and(array_keys(ReaderPage::srcset($picture['srcset'])))->toBe([640, 1280, 2560])
        ->and($picture['sizes'])->not->toBe('')
        ->and($picture['src'])->toBe(ReaderPage::srcset($picture['srcset'])[1280]);

    foreach (ReaderPage::srcset($picture['srcset']) as $width => $address) {
        $response = $this->get($address)->assertOk()->assertHeader('Content-Type', 'image/webp');
        expect(getimagesizefromstring((string) $response->streamedContent())[0])->toBe($width);
    }
});

it('serves a handheld Picture at 360 wide from its 720-wide file', function () {
    $picture = ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('img.picture-handheld')[0];

    expect($picture['width'])->toBe('360')
        ->and($picture['height'])->toBe('640')
        ->and(array_keys(ReaderPage::srcset($picture['srcset'])))->toBe([360, 720]);

    foreach (ReaderPage::srcset($picture['srcset']) as $width => $address) {
        $response = $this->get($address)->assertOk();
        expect(getimagesizefromstring((string) $response->streamedContent())[0])->toBe($width);
    }
});

it('caches a Picture for a year at an address that changes when the Picture does', function () {
    $before = ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('img.picture-desk')[0]['src'];

    expect($before)->toMatch('#^/pictures/[0-9a-f]{16}/inbound-shipments-open-a-shipment-1280\.webp$#');
    $this->get($before)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $this->content->picture('inbound-shipments-open-a-shipment', [
        'alt' => 'The Inbound Shipments list with the shipment from Harbour Foods outlined.',
    ], height: 1600, shade: '#FFFFFF')->publish();
    $after = ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('img.picture-desk')[0]['src'];

    expect($after)->not->toBe($before)
        ->and($after)->toMatch('#^/pictures/[0-9a-f]{16}/inbound-shipments-open-a-shipment-1280\.webp$#');
});

it('answers page not found for a Picture address it never served', function (string $address) {
    $this->get($address)->assertNotFound();
})->with([
    'an unknown fingerprint' => '/pictures/0123456789abcdef/inbound-shipments-open-a-shipment-1280.webp',
    'a size it never made' => fn () => preg_replace('/-1280\.webp$/', '-999.webp', ReaderPage::of($this->get('/articles/receive-at-the-dock'))->attributes('img.picture-desk')[0]['src']),
    'a way out of the folder' => '/pictures/0123456789abcdef/..%2F..%2Fsecret-1280.webp',
]);

it('fits its Pictures on the page at every width, with no sideways scrolling', function (int $width) {
    $layout = BrowserLayout::of($this->get('/articles/receive-at-the-dock'), width: $width, selectors: ['.prose', 'img.picture-desk', 'img.picture-handheld']);

    expect($layout->scrollWidth)->toBeLessThanOrEqual($width)
        ->and($layout->box('img.picture-desk')['width'])->toBeLessThanOrEqual($layout->box('.prose')['width'])
        ->and($layout->box('img.picture-desk')['height'])->toBeGreaterThan(0)
        ->and($layout->box('img.picture-handheld')['width'])->toBeLessThanOrEqual(min(360, $layout->box('.prose')['width']));
})->with([320, 390, 1280]);

describe('the rebuild', function () {
    it('fails on a missing Picture, naming the Article and the Picture Name', function () {
        $content = content()->article('receive-at-the-dock', [], "1. Scan the pallet.\n\n   ![](picture:dock-receiving-terminal-scan-pallet)\n");
        $line = $content->lineOf('articles/receive-at-the-dock.md', '![](picture:');

        $result = $content->rebuild();

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain("articles/receive-at-the-dock.md: Picture: line {$line}: there is no Picture named \"dock-receiving-terminal-scan-pallet\"");
    });

    it('changes nothing when a Picture is missing', function () {
        $this->content->article('receive-at-the-dock', ['title' => 'A new title'], "![](picture:no-such-picture)\n");

        expect($this->content->rebuild()['exitCode'])->toBe(1);
        $this->get('/articles/receive-at-the-dock')->assertOk()->assertDontSee('A new title');
    });

    it('fails on a Picture it can\'t show properly', function (array|string $recipe, ?int $width, string $problem) {
        $content = content()->picture('goods-in-scan-delivery', is_array($recipe) ? $recipe : [], width: $width);
        if (is_string($recipe)) {
            $content->pictureFile('goods-in-scan-delivery.yaml', $recipe);
        }
        $content->article('receive-at-the-dock', [], "![](picture:goods-in-scan-delivery)\n");
        $line = $content->lineOf('articles/receive-at-the-dock.md', '![](picture:');

        $result = $content->rebuild();

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain("articles/receive-at-the-dock.md: Picture: line {$line}: {$problem}");
    })->with([
        'no alt text in its Recipe' => [['alt' => null], null, "the Recipe goods-in-scan-delivery.yaml can't be used: alt: is missing"],
        'empty alt text' => [['alt' => ''], null, "the Recipe goods-in-scan-delivery.yaml can't be used: alt: must be text"],
        'no device' => [['device' => null], null, "the Recipe goods-in-scan-delivery.yaml can't be used: device: is missing"],
        'an unknown device' => [['device' => 'tablet'], null, "the Recipe goods-in-scan-delivery.yaml can't be used: device: must be desk or handheld"],
        'a Recipe the picture tool would refuse' => [['alt' => 'Screenshot of the Goods in screen.'], null, "the Recipe goods-in-scan-delivery.yaml can't be used: alt: must not start \"Screenshot\""],
        'a Recipe that isn\'t YAML' => ["alt: [unclosed\n", null, "the Recipe goods-in-scan-delivery.yaml can't be used: is not a YAML mapping of Recipe fields"],
        'not the picture tool\'s size' => [[], 1920, 'goods-in-scan-delivery.png is 1920 pixels wide, but the picture tool takes a desk Picture 2560 wide'],
    ]);

    it('fails on a Picture with no Recipe', function () {
        $content = content()->picture('goods-in-scan-delivery')
            ->article('receive-at-the-dock', [], "![](picture:goods-in-scan-delivery)\n");
        unlink($content->picturesPath().'/goods-in-scan-delivery.yaml');

        $line = $content->lineOf('articles/receive-at-the-dock.md', '![](picture:');

        expect($content->rebuild()['output'])
            ->toContain("articles/receive-at-the-dock.md: Picture: line {$line}: \"goods-in-scan-delivery\" has no Recipe (goods-in-scan-delivery.yaml) to give its alt text");
    });

    it('takes alt text only from the Recipe and images only from the pictures folder', function (string $markdown, string $problem) {
        $content = content()->picture('goods-in-scan-delivery')->article('receive-at-the-dock', [], "Do the task.\n\n{$markdown}\n");
        $line = $content->lineOf('articles/receive-at-the-dock.md', $markdown);

        $result = $content->rebuild();

        expect($result['exitCode'])->toBe(1)
            ->and($result['output'])->toContain("articles/receive-at-the-dock.md: Picture: line {$line}: {$problem}");
    })->with([
        'alt text in the Article' => ['![The Goods in screen](picture:goods-in-scan-delivery)', 'write the alt text in the Recipe goods-in-scan-delivery.yaml, not the Article: ![](picture:goods-in-scan-delivery)'],
        'an image by address' => ['![](https://example.com/goods-in.png)', 'show a Picture by its Picture Name, as ![](picture:<Picture Name>); "https://example.com/goods-in.png" isn\'t one'],
        'an image from the folder by path' => ['![](../../pictures/goods-in-scan-delivery.png)', 'show a Picture by its Picture Name, as ![](picture:<Picture Name>); "../../pictures/goods-in-scan-delivery.png" isn\'t one'],
        'a Picture Name written wrongly' => ['![](picture:Goods_In)', '"Goods_In" isn\'t a Picture Name: lower-case letters and numbers joined by single hyphens'],
    ]);
});
