<?php

namespace App\Fringe;

/**
 * What we managed to pull off a review page. Every field is optional: the generator fills in
 * what it got and leaves the rest for the user, which is the whole failure story — a partial
 * parse is a usable result, not an error.
 */
class ScrapedReview
{
    public function __construct(
        public readonly string $sourceName,
        public readonly ?string $title = null,
        /** @var string[] */
        public readonly array $lines = [],
        public readonly ?float $stars = null,
        public readonly ?string $attribution = null,
        /** Background image as a data: URI — see ReviewScraper::image() for why not a URL. */
        public readonly ?string $image = null,
        /** Set when something was fetched but couldn't be read; the builder still opens. */
        public readonly ?string $warning = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [] && $this->stars === null && $this->image === null;
    }

    public function withWarning(string $warning): self
    {
        return new self(
            $this->sourceName, $this->title, $this->lines, $this->stars,
            $this->attribution, $this->image, $warning,
        );
    }
}
