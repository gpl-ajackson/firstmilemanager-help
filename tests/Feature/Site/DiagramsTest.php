<?php

use App\Site\Asset;
use Tests\Support\ReaderPage;

/*
 * Spec #30 user story 34 and Further Notes 8: a diagram written as Mermaid text in an Article
 * is drawn as an image during the content rebuild, with alt text in words, so no diagram script
 * ever reaches the reader. Drawing needs tools/diagrams installed (npm ci --prefix tools/diagrams).
 */

const LIFECYCLE = <<<'MD'
    ## How a Delivery moves

    ```mermaid
    flowchart LR
      accDescr: A Delivery is received at the dock, then put away, then picked.
      A[Received at the dock] --> B[Put away] --> C[Picked]
    ```

    Each step is done on the Dock Receiving Terminal.
    MD;

it('draws a Mermaid diagram as an image at rebuild time, with its alt text in words', function () {
    content()->article('how-a-delivery-moves', ['type' => 'Concept'], LIFECYCLE)->publish();

    $page = ReaderPage::of($this->get('/articles/how-a-delivery-moves'));
    $diagram = $page->attributes('.prose .diagram img');

    expect($diagram)->toHaveCount(1)
        ->and($diagram[0]['alt'])->toBe('A Delivery is received at the dock, then put away, then picked.')
        ->and($diagram[0]['src'])->toMatch('#^/pictures/[0-9a-f]{16}/diagram-\d+\.webp$#')
        ->and((int) $diagram[0]['width'])->toBeGreaterThan(200)
        ->and((int) $diagram[0]['height'])->toBeGreaterThan(50);

    $sources = ReaderPage::srcset($diagram[0]['srcset']);
    expect(array_keys($sources))->toBe([(int) $diagram[0]['width'], 2 * (int) $diagram[0]['width']]);
    foreach ($sources as $width => $address) {
        $image = $this->get($address)->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        expect(getimagesizefromstring((string) $image->streamedContent())[0])->toBe($width);
    }
});

it('keeps a diagram\'s address while its text stays the same, and changes it when the text does', function () {
    $content = content()->article('how-a-delivery-moves', ['type' => 'Concept'], LIFECYCLE)->publish();
    $first = ReaderPage::of($this->get('/articles/how-a-delivery-moves'))->attributes('.diagram img')[0]['src'];

    $content->article('how-a-delivery-moves', ['type' => 'Concept', 'title' => 'How a Delivery moves'], LIFECYCLE)->publish();
    $again = ReaderPage::of($this->get('/articles/how-a-delivery-moves'))->attributes('.diagram img')[0]['src'];

    $content->article('how-a-delivery-moves', ['type' => 'Concept'], str_replace('[Picked]', '[Picked and packed]', LIFECYCLE))->publish();
    $changed = ReaderPage::of($this->get('/articles/how-a-delivery-moves'))->attributes('.diagram img')[0]['src'];

    expect($again)->toBe($first)
        ->and($changed)->not->toBe($first);
    $this->get($changed)->assertOk();
});

it('sends the reader no diagram script and no Mermaid text', function () {
    content()->article('how-a-delivery-moves', ['type' => 'Concept'], LIFECYCLE)->publish();

    $response = $this->get('/articles/how-a-delivery-moves')
        ->assertOk()
        ->assertDontSee('mermaid')
        ->assertDontSee('flowchart')
        ->assertDontSee('-->', escape: false)
        ->assertSee('Each step is done on the Dock Receiving Terminal.');
    // The page's only script is the site's own (public/js/site.js), which draws nothing.
    expect(ReaderPage::of($response)->attributes('script'))->toBe([['src' => Asset::url('js/site.js'), 'defer' => '']]);
});

it('fails the rebuild for a diagram with no alt text', function () {
    $content = content()->article('how-a-delivery-moves', ['type' => 'Concept'], <<<'MD'
        Intro.

        ```mermaid
        flowchart LR
          A[Received] --> B[Put away]
        ```
        MD);
    $line = $content->lineOf('articles/how-a-delivery-moves.md', '```mermaid');

    $result = $content->rebuild();

    expect($result['exitCode'])->toBe(1)
        ->and($result['output'])->toContain("articles/how-a-delivery-moves.md: diagram: line {$line}: write its alt text in words as \"accDescr: <what it shows>\" on the line after \"flowchart LR\"");
});

it('fails the rebuild for a diagram Mermaid can\'t read, naming the Article', function () {
    $content = content()->article('how-a-delivery-moves', ['type' => 'Concept'], <<<'MD'
        Intro.

        ```mermaid
        flowchart LR
          accDescr: A Delivery is received.
          A[Received] -->
        ```
        MD);
    $line = $content->lineOf('articles/how-a-delivery-moves.md', '```mermaid');

    $result = $content->rebuild();

    expect($result['exitCode'])->toBe(1)
        ->and($result['output'])->toContain("articles/how-a-delivery-moves.md: diagram: line {$line}: Mermaid can't read it: Parse error:");
});
