<?php

namespace App\Actions;

use App\Newsletter\PostEmail;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry as EntryContract;

use function Statamic\trans as __;

/**
 * "Preview newsletter": opens the email version of the entry, exactly as it would be sent
 * to Kit, so it can be read before drafting. See App\Http\Controllers\CP\NewsletterController.
 */
class PreviewNewsletter extends Action
{
    protected $icon = 'eye';

    protected $confirm = false;

    public static function title()
    {
        return __('Preview newsletter');
    }

    public function visibleTo($item)
    {
        return $item instanceof EntryContract && PostEmail::supports($item);
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function redirect($items, $values)
    {
        return cp_route('newsletter.preview', $items->first()->id());
    }
}
