<?php

namespace App\Fieldtypes;

use Statamic\Fields\Fieldtype;

class TicketImport extends Fieldtype
{
    protected static $handle = 'ticket_import';

    public function process($data)
    {
        return null;
    }

    public function preProcess($data)
    {
        return null;
    }
}
