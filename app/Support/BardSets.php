<?php

namespace App\Support;

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
     * The first value of a field that may be stored as a bare id or a single-element array,
     * which is how Statamic writes a relationship field depending on its max_items.
     */
    public static function first($value)
    {
        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
