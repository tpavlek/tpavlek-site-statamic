<?php

namespace App\Fringe;

/**
 * The ticket site served its AWS WAF "Human Verification" challenge instead of data — we've
 * been throttled. Thrown by TicketAvailability so a run can stop and checkpoint rather than
 * record the challenge as a festival with no showtimes. Not something to catch and retry in
 * a tight loop: the answer is to wait and resume later.
 */
class TicketSiteBlocked extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('tickets.fringetheatre.ca served a WAF challenge; backing off.');
    }
}
