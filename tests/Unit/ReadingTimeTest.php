<?php

namespace Tests\Unit;

use App\Support\ReadingTime;
use PHPUnit\Framework\TestCase;

/**
 * Read time counts the prose inside sets, not just paragraphs. Pure: builds node arrays,
 * touches no content.
 */
class ReadingTimeTest extends TestCase
{
    private function para(int $words): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => trim(str_repeat('word ', $words))]]];
    }

    private function set(array $values): array
    {
        return ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => $values]];
    }

    public function test_empty_body_has_no_read_time(): void
    {
        $this->assertNull(ReadingTime::minutes([]));
        $this->assertNull(ReadingTime::minutes(null));
    }

    public function test_short_body_is_at_least_a_minute(): void
    {
        $this->assertSame(1, ReadingTime::minutes([$this->para(20)]));
    }

    public function test_prose_inside_sets_counts(): void
    {
        $words = trim(str_repeat('word ', 230));
        $nodes = [
            $this->para(230),
            $this->set(['type' => 'source_quote', 'quote' => $words, 'source' => 'CBC', 'source_url' => 'https://example.com/a/b/c']),
            $this->set(['type' => 'dialogue', 'style' => 'chat', 'lines' => [['id' => 'a', 'speaker' => 'Troy', 'line' => $words]],
                'people' => [['id' => 'b', 'name' => 'Troy', 'photo' => ['troy.png']]]]),
        ];

        $this->assertSame(3, ReadingTime::minutes($nodes));
    }

    public function test_markdown_link_targets_are_not_words(): void
    {
        $nodes = [$this->set(['type' => 'timeline', 'entries' => [
            ['id' => 'a', 'year' => '2017', 'text' => '[announced](https://example.com/one/two/three/four/five/six)'],
        ]])];

        $this->assertSame(1, ReadingTime::minutes($nodes));
    }

    public function test_images_add_looking_time(): void
    {
        $images = $this->set(['type' => 'gallery', 'media' => array_fill(0, 10, 'a.jpg')]);

        // 115 words is half a minute; ten images add 12+11+...+3 = 75 seconds.
        $this->assertSame(1, ReadingTime::minutes([$this->para(115)]));
        $this->assertSame(2, ReadingTime::minutes([$this->para(115), $images]));
    }
}
