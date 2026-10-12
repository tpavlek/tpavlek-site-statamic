<?php

namespace App\Actions;

use App\Newsletter\Kit;
use App\Newsletter\PostEmail;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry as EntryContract;

use function Statamic\trans as __;

/**
 * "Draft newsletter in Kit" on a post or a video with a companion article.
 *
 * Renders the entry as an email (App\Newsletter\PostEmail) and saves it to Kit as a draft
 * broadcast. Nothing is emailed from here: the draft gets checked and sent in Kit. See
 * App\Newsletter\Kit for why that's the only option Kit's API offers anyway.
 *
 * The broadcast id is recorded on the entry, and a second draft needs the toggle, so a
 * double click can't leave two copies of the same email waiting to go out.
 */
class DraftNewsletter extends Action
{
    protected $icon = 'mail';

    protected $fields = [
        'again' => [
            'type' => 'toggle',
            'display' => 'Draft again',
            'instructions' => 'Only needed if this has already been drafted in Kit, for instance after editing it. Delete the old draft in Kit first, or both will be there.',
        ],
    ];

    public static function title()
    {
        return __('Draft newsletter in Kit');
    }

    public function visibleTo($item)
    {
        return $item instanceof EntryContract && PostEmail::supports($item);
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function buttonText()
    {
        return __('Create draft');
    }

    public function confirmationText()
    {
        return __('This saves the post as a draft broadcast in Kit. Nothing is emailed until you send it from Kit.');
    }

    public function run($items, $values)
    {
        $entry = $items->first();

        if (! $entry) {
            return __('Nothing selected.');
        }

        if ($entry->value('newsletter_broadcast_id') && ! ($values['again'] ?? false)) {
            return __('Already drafted in Kit (broadcast :id). Turn on "Draft again" to make another.', ['id' => $entry->value('newsletter_broadcast_id')]);
        }

        $email = new PostEmail($entry);

        $broadcast = app(Kit::class)->draftBroadcast(
            $email->subject(),
            $email->previewText(),
            $email->html(),
            'troypavlek.ca: '.$email->subject(),
        );

        // set()->save(), never a CP update request, which would replace the entry's data with
        // only what was submitted.
        $entry->set('newsletter_broadcast_id', $broadcast['id'])
            ->set('newsletter_drafted_at', now()->toIso8601String())
            ->save();

        return __('Draft created in Kit. Review and send it from Broadcasts in Kit.');
    }
}
