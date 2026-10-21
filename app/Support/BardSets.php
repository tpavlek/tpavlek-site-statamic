<?php

namespace App\Support;

use Statamic\Facades\Entry;

/**
 * Pulls sets of a given type out of a Bard value, in the order they appear.
 *
 * Bard content is a nested node tree — a set can sit inside a list item or a blockquote as
 * easily as at the top level — so this walks rather than scanning the first level. Three
 * callers wanted the same walk (the post's schema, its OpenGraph card, and which festivals
 * it covers), which is exactly the kind of quiet duplication that drifts.
 */
class BardSets
{
    /** @return array<int, array> the `values` of each matching set */
    public static function ofType($nodes, string $type): array
    {
        $found = [];

        foreach ((array) $nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $values = $node['attrs']['values'] ?? null;

            if (($values['type'] ?? null) === $type) {
                $found[] = $values;
            }

            if (isset($node['content'])) {
                $found = array_merge($found, self::ofType($node['content'], $type));
            }
        }

        return $found;
    }

    /**
     * The plain text of each top-level paragraph, in order, skipping empty ones. Sets and
     * other blocks are left out, so a callout or image ahead of the opening line doesn't
     * become the summary. An inline review pin stores only the review's id, so it reads as
     * the show's title; without that, "<pin> is held over" summarised as " is held over".
     *
     * @return array<int, string>
     */
    public static function paragraphs($nodes): array
    {
        $paragraphs = [];

        foreach ((array) $nodes as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'paragraph') {
                continue;
            }

            $text = collect($node['content'] ?? [])->map(function ($inline) {
                if (($inline['type'] ?? null) === 'btsPin') {
                    $id = self::first($inline['attrs']['values']['review'] ?? null);

                    return $id ? (Entry::find($id)?->value('title') ?? '') : '';
                }

                return $inline['text'] ?? '';
            })->implode('');

            $text = trim(preg_replace('/\s+/', ' ', $text));

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

    /**
     * The first value of a field that may be stored as a bare id or a single-element array,
     * which is how Statamic writes a relationship field depending on its max_items.
     */
    public static function first($value)
    {
        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
