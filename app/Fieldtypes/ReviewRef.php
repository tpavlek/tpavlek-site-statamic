<?php

namespace App\Fieldtypes;

use Statamic\Fieldtypes\Entries;

/**
 * An entries field that says which festival each review is from.
 *
 * Shows return year after year under the same name — there are two "Edmontask" reviews, two
 * "Late Night Cabaret" — so a dropdown listing titles alone gives you no way to tell which
 * one you're linking to. Statamic renders a fieldtype's item hint as a badge beside the title
 * in select and typeahead mode, which is the supported way to add that without touching the
 * entries' actual titles.
 *
 * Used by the review_ref bard pin. Registered in AppServiceProvider as `review_ref_entries`.
 */
class ReviewRef extends Entries
{
    protected static $handle = 'review_ref_entries';

    public function getItemHint($item): ?string
    {
        $festival = $item->value('festival');
        $festival = is_array($festival) ? ($festival[0] ?? null) : $festival;

        // Keep whatever the parent would have said (collection name, site) alongside it.
        return collect([
            $festival ? 'Fringe '.$festival : null,
            parent::getItemHint($item),
        ])->filter()->implode(' • ') ?: null;
    }
}
