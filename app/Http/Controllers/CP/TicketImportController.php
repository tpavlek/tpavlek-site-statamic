<?php

namespace App\Http\Controllers\CP;

use App\Fringe\TicketPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Statamic\Facades\Collection as CollectionFacade;

/**
 * The paste-a-ticket-link field on the review create form.
 *
 * The reading of the page lives in App\Fringe\TicketPage, shared with the lineup import
 * command — a second parser would quietly produce a second set of venue and artist entries.
 * This controller's own job is only turning those values into publish-form fields.
 */
class TicketImportController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', 'regex:#^https://tickets\.fringetheatre\.ca/event/#'],
        ]);

        $url = rtrim($request->input('url'), '/').'/';

        $html = TicketPage::fetch($url);

        if ($html === null) {
            return response()->json(['message' => 'The ticketing site did not respond. Try again in a moment.'], 422);
        }

        $raw = TicketPage::fields($html, $url);

        if ($raw === null) {
            return response()->json(['message' => "Couldn't read show details from that page — the ticketing site may have changed. Fill the fields in manually."], 422);
        }

        // Pasting a link here means Troy is creating a review for a show he means to see.
        // The bulk lineup import sets `pending` instead — see App\Console\Commands\ImportFringeLineup.
        $raw['recommendation'] = 'watchlist';

        return response()->json(['fields' => $this->publishFields($raw)]);
    }

    /**
     * Convert raw stored-format values into publish-form values and meta, so the
     * fieldtype can drop them straight into the create form.
     */
    private function publishFields(array $raw): array
    {
        $blueprint = CollectionFacade::findByHandle('fringe_reviews')->entryBlueprint();

        $fields = [];

        foreach ($raw as $handle => $value) {
            $field = $blueprint->field($handle);

            if (! $field) {
                continue;
            }

            $processed = $field->newInstance()->setValue($value)->preProcess();

            $fields[$handle] = [
                'value' => $processed->value(),
                'meta' => $processed->fieldtype()->preload(),
            ];
        }

        return $fields;
    }

}
