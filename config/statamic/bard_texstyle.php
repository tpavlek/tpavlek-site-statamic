<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pins
    |--------------------------------------------------------------------------
    |
    | Inline elements available within Bard content. Rendered by partials in
    | resources/views/partials/pins/_[handle].antlers.html
    |
    */

    'pins' => [

        'review_ref' => [
            'display' => 'Review Reference',
            'instructions' => 'Link to another fringe review inline, shown with its stars and year.',
            'fields' => [
                'review' => [
                    'display' => 'Review',
                    'type' => 'review_ref_entries',
                    'collections' => ['fringe_reviews'],
                    'max_items' => 1,
                    'mode' => 'select',
                    'preview' => true,
                ],
            ],
        ],

    ],

];
