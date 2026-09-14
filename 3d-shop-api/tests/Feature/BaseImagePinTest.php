<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class BaseImagePinTest extends TestCase
{
    public function test_every_base_image_is_pinned_by_digest(): void
    {
        $loose = [];

        foreach ($this->dockerfiles() as $path => $contents) {
            $stages = $this->stageNames($contents);

            preg_match_all('/^(?:FROM|ARG \w*IMAGE=)\s*(\S+)/m', $contents, $matches);

            foreach ($matches[1] as $reference) {
                $pinned = str_contains($reference, '@sha256:')
                    || str_starts_with($reference, '$')
                    || in_array($reference, $stages, true);

                if (! $pinned) {
                    $loose[] = "{$path} -> {$reference}";
                }
            }
        }

        $this->assertSame([], $loose, 'These can change under the build: '.implode(', ', $loose));
    }

    /** A FROM naming an earlier stage in the same file pins nothing of its own. */
    private function stageNames(string $contents): array
    {
        preg_match_all('/^FROM\s+\S+\s+AS\s+(\S+)/mi', $contents, $matches);

        return $matches[1];
    }

    /** @return array<string, string> */
    private function dockerfiles(): array
    {
        $files = (new Finder)
            ->files()
            ->in(base_path('..'))
            ->exclude(['node_modules', 'vendor', 'build', '.git'])
            ->name('*Dockerfile*');

        $found = [];

        foreach ($files as $file) {
            $found[$file->getRelativePathname()] = (string) $file->getContents();
        }

        $this->assertNotEmpty($found, 'No Dockerfiles were found to check.');

        return $found;
    }
}
