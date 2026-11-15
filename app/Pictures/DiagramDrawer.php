<?php

namespace App\Pictures;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Draws Mermaid diagrams as double-density PNG images by running tools/diagrams/render.cjs
 * (Mermaid in headless Chromium through Playwright, both pinned in tools/diagrams/package.json).
 * Only the content rebuild runs it, and only for a diagram it hasn't drawn before, so Mermaid
 * never runs while a reader waits and never reaches a page.
 */
class DiagramDrawer
{
    public function __construct(
        private readonly string $script,
        private readonly string $fonts,
    ) {}

    /**
     * What the drawing depends on besides the diagram (the script, its brand colours, and the
     * pinned Mermaid and Playwright), so changing any of it draws every diagram again.
     */
    public function version(): string
    {
        $folder = dirname($this->script);

        return hash('xxh128', @file_get_contents($this->script).@file_get_contents($folder.'/package-lock.json'));
    }

    /**
     * Draws each diagram's Mermaid source into its PNG file.
     *
     * @param  list<array{source: string, file: string}>  $diagrams
     * @return list<array{width: int, height: int}|array{error: string}> In the same order: its size in CSS pixels (the file is twice that), or what Mermaid said was wrong.
     *
     * @throws RuntimeException when diagrams can't be drawn on this machine at all.
     */
    public function draw(array $diagrams): array
    {
        if (! is_dir(dirname($this->script).'/node_modules/mermaid')) {
            throw new RuntimeException('the diagram tool is not installed: run "npm ci --prefix tools/diagrams"');
        }

        $result = Process::timeout(300)
            ->input((string) json_encode(['fonts' => $this->fonts, 'diagrams' => $diagrams]))
            ->run(['node', $this->script]);

        /** @var mixed $drawn */
        $drawn = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($drawn) || count($drawn) !== count($diagrams)) {
            throw new RuntimeException('the diagram tool failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return array_map(function (mixed $one): array {
            if (is_array($one) && is_string($one['error'] ?? null)) {
                return ['error' => $one['error']];
            }
            if (is_array($one) && is_int($one['width'] ?? null) && is_int($one['height'] ?? null)) {
                return ['width' => $one['width'], 'height' => $one['height']];
            }

            throw new RuntimeException('the diagram tool gave an answer it shouldn\'t: '.json_encode($one));
        }, array_values($drawn));
    }
}
