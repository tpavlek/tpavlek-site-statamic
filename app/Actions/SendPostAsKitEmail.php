<?php

namespace App\Actions;

use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;
use Statamic\Entries\Entry;

class SendPostAsKitEmail extends Action
{

    protected $dangerous = true;

    public static function title()
    {
        return __("Send to Kit Subscribers");
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && ($this->context['collection'] ?? '') === 'posts';
    }

    /**
     * The run method
     *
     * @return mixed
     */
    public function run($items, $values)
    {
        if (count($items) != 1) {
            return __('Did not send, select only one post');
        }

        /** @var Entry $item */
        $item = $items[0];

        $view = (new \Statamic\View\View)
            ->template('posts.show')
            ->layout('layout')
            ->cascadeContent($item);

        Log::debug($view->render());
    }
}
