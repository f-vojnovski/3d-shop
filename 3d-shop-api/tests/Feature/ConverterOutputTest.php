<?php

namespace Tests\Feature;

use App\Support\ModelConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** The converter reads a file a container wrote, so it has to look where the container was told to write. */
class ConverterOutputTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('app/private/convert/converter-output-test');
        File::deleteDirectory($this->scratch);
        File::makeDirectory($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_it_finds_the_file_the_container_wrote(): void
    {
        $result = $this->convertWith(fn () => $this->writeConverted('a converted model'));

        $this->assertSame('ok', $result['status']);
        $this->assertFileExists($result['path']);
        $this->assertSame(
            $this->scratch.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'converted.glb',
            $result['path'],
            'The container may only write to /out, which is the out directory.'
        );
    }

    /** The broker knows which build ran; the converter records it as the renderer does. */
    public function test_it_records_the_image_that_converted(): void
    {
        $result = $this->convertWith(fn () => $this->writeConverted('a converted model'));

        $this->assertSame('sha256:'.str_repeat('e', 64), $result['tool']);
    }

    public function test_a_container_that_wrote_nothing_is_a_failure(): void
    {
        $result = $this->convertWith(fn () => null);

        $this->assertSame('failed', $result['status']);
        $this->assertArrayNotHasKey('path', $result);
    }

    public function test_an_empty_file_is_a_failure(): void
    {
        $result = $this->convertWith(fn () => $this->writeConverted(''));

        $this->assertSame('failed', $result['status']);
    }

    /**
     * $writes stands in for the container, at the moment the broker would be waiting.
     *
     * @return array<string, mixed>
     */
    private function convertWith(callable $writes): array
    {
        Redis::shouldReceive('rpush')->andReturnTrue();
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('blpop')->andReturnUsing(function () use ($writes) {
            $writes();

            return ['key', json_encode([
                'status' => 'ran',
                'exit' => 0,
                'output' => '',
                'image' => 'sha256:'.str_repeat('e', 64),
            ])];
        });

        return (new ModelConverter)->toGlb($this->scratch.DIRECTORY_SEPARATOR.'model', $this->scratch);
    }

    /** Written from another process, as the container does. */
    private function writeConverted(string $contents): void
    {
        $out = $this->scratch.DIRECTORY_SEPARATOR.'out';
        File::ensureDirectoryExists($out, 0775, true);

        $target = $out.DIRECTORY_SEPARATOR.'converted.glb';
        $script = sprintf(
            'file_put_contents(%s, %s);',
            var_export($target, true),
            var_export($contents, true)
        );

        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
    }
}
