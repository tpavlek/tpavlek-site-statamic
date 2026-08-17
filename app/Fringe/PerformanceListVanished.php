<?php

namespace App\Fringe;

/**
 * The ticket site returned an empty performance list for a show we already hold showtimes
 * for. Mid-festival a show does not lose its entire run — this is a failed request wearing
 * a success's clothes (a 500, a dropped connection, an unparseable body all come back from
 * TicketAvailability::performances as []), and storing it would wipe real data, which is
 * exactly what happened to 13 shows on 2026-08-16. Thrown by ShowScraper::plan so callers
 * keep the prior records and try again later.
 */
class PerformanceListVanished extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ticket site returned no performances for a show that has showtimes; keeping the prior data.');
    }
}
