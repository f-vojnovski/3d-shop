<?php

namespace Tests\Feature;

use App\Models\ProductFile;
use Tests\TestCase;

/**
 * Two workers storing in the same microsecond used to produce one name, and the
 * second write replaced the first object without anything noticing.
 */
class StoredNameTest extends TestCase
{
    public function test_names_do_not_repeat_within_a_microsecond(): void
    {
        $names = [];

        for ($i = 0; $i < 20_000; $i++) {
            $names[] = ProductFile::storedName('png');
        }

        $this->assertCount(
            count($names),
            array_unique($names),
            'Two stored objects were given the same name, so one would replace the other.'
        );
    }

    /** Time-based names collide precisely because they are time-based. */
    public function test_the_name_is_not_derived_from_the_clock(): void
    {
        $first = ProductFile::storedName();
        $second = ProductFile::storedName();

        $this->assertNotSame($first, $second);
        $this->assertSame(32, strlen($first), 'A shorter name is a likelier collision.');
    }

    public function test_the_extension_is_kept_and_optional(): void
    {
        $this->assertStringEndsWith('.png', ProductFile::storedName('png'));
        $this->assertStringNotContainsString('.', ProductFile::storedName());
    }
}
