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
        /**
         * Sentences grouped by paragraph, in the order they appear in the article.
         *
         * The quote picker shows the review as it reads and lets the user select a run of
         * sentences, so the paragraph structure has to survive the scrape — a flat list
         * can't say where one paragraph ends and the next begins, and the excerpt needs
         * that to put the line breaks back.
         *
         * @var string[][]
         */
        public readonly array $paragraphs = [],
        public readonly ?float $stars = null,
        public readonly ?string $attribution = null,
        /** Background image as a data: URI — see ReviewScraper::image() for why not a URL. */
        public readonly ?string $image = null,
        /** Set when something was fetched but couldn't be read; the builder still opens. */
        public readonly ?string $warning = null,
        /**
         * The source's own id for this review, where a page carries several of them.
         *
         * reviews.fringetheatre.ca lists every review for a show on the show's page, so the
         * artist picks one before the builder opens. The pick round-trips as this id rather
         * than a position, because a review posted in between would shift the positions and
         * silently hand them a different review.
         */
        public readonly ?string $reviewId = null,
        /** Who wrote it, and when — shown in the chooser so the artist can tell them apart. */
        public readonly ?string $reviewer = null,
        public readonly ?string $reviewedAt = null,
    ) {}

    /**
     * Every sentence, flattened.
     *
     * @return string[]
     */
    public function sentences(): array
    {
        return $this->paragraphs === [] ? [] : array_merge(...array_values($this->paragraphs));
    }

    /** What the card starts on: the review's opening sentence. */
    public function openingLine(): ?string
    {
        return $this->paragraphs[0][0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->paragraphs === [] && $this->stars === null && $this->image === null;
    }

    public function withWarning(string $warning): self
    {
        return new self(
            $this->sourceName, $this->title, $this->paragraphs, $this->stars,
            $this->attribution, $this->image, $warning,
            $this->reviewId, $this->reviewer, $this->reviewedAt,
        );
    }

    /** A line or so of the review, for telling one apart from another in the chooser. */
    public function excerpt(int $length = 220): string
    {
        $text = implode(' ', $this->sentences());

        return mb_strlen($text) > $length
            ? rtrim(mb_substr($text, 0, $length)).'…'
            : $text;
    }
}
