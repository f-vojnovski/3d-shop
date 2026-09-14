<?php

namespace Tests\Feature;

use Tests\TestCase;

class RenderImagePathsTest extends TestCase
{
    private const FILTER = '../.github/render-image-paths.txt';

    /** Miss one and CI keeps the old image after its source changed. */
    public function test_every_file_copied_into_the_render_image_rebuilds_it(): void
    {
        $patterns = $this->patterns();
        $uncovered = [];

        foreach ($this->copiedPaths() as $path) {
            $covered = false;

            foreach ($patterns as $pattern) {
                if (str_ends_with($pattern, '/') ? str_starts_with($path, $pattern) : $path === $pattern) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $uncovered[] = $path;
            }
        }

        $this->assertSame([], $uncovered, 'Add these to .github/render-image-paths.txt: '.implode(', ', $uncovered));
    }

    public function test_the_filter_lists_nothing_that_does_not_exist(): void
    {
        foreach ($this->patterns() as $pattern) {
            $this->assertFileExists(base_path('../'.rtrim($pattern, '/')), "The filter lists {$pattern}, which is gone.");
        }
    }

    /** @return list<string> repo-relative sources of every COPY, skipping earlier build stages */
    private function copiedPaths(): array
    {
        preg_match_all(
            '/^COPY\s+(?!--from)(?:--\S+\s+)*(\S+)/m',
            (string) file_get_contents(base_path('render/Dockerfile')),
            $matches
        );

        $this->assertNotEmpty($matches[1], 'No COPY lines were found to check.');

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private function patterns(): array
    {
        $lines = file(base_path(self::FILTER), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(
            array_map('trim', $lines ?: []),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        ));
    }
}
