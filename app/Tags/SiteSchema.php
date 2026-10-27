<?php

namespace App\Tags;

use App\Schema\PostSchema;
use Statamic\Tags\Tags;

/**
 * schema.org markup for the homepage: the site, and the person it belongs to.
 *
 *     {{ site_schema }}
 *
 * Every article already credits Troy as its author; this is the one place that says the
 * site itself is his, so a search engine or an AI model asked about him has a page to anchor
 * that on. The Person is the same one the articles use (PostSchema::author), sameAs and all,
 * so the two can't drift apart.
 */
class SiteSchema extends Tags
{
    public function index(): string
    {
        $person = ['@id' => 'https://troypavlek.ca/#troy', ...PostSchema::author()];

        $data = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => 'https://troypavlek.ca/#website',
                    'url' => 'https://troypavlek.ca',
                    'name' => 'Troy Pavlek',
                    'inLanguage' => 'en-CA',
                    'publisher' => ['@id' => 'https://troypavlek.ca/#troy'],
                ],
                $person,
            ],
        ];

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );

        return '<script type="application/ld+json">'.$json.'</script>';
    }
}
