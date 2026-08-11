<?php

return [

    /*
    | Gates /fringe/availability.json, the shareable export of the sold-out snapshot
    | including exact seat counts. Those numbers are deliberately admin-only everywhere
    | else on the site (see ShowAvailability), so the export is 404 unless the request
    | carries this key — and 404 for everyone when the key is unset.
    */
    'availability_share_key' => env('FRINGE_AVAILABILITY_SHARE_KEY'),

];
