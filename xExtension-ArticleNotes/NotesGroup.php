<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Everything one user annotated on one article or web page that matches a filter. */
final class NotesGroup {
	/**
	 * @param string|null $entryId the FreshRSS article, null for a page
	 * @param string $url the article's link or the page URL ('' when there is none)
	 * @param bool $exists whether FreshRSS still has the article (always true for pages)
	 * @param list<Highlight> $highlights in document order
	 * @param list<string> $orphaned uuids of the highlights whose passage is no longer in the (still existing) article
	 */
	public function __construct(
		public readonly ?string $entryId,
		public readonly string $title,
		public readonly string $url,
		public readonly string $feedName,
		public readonly int $date,
		public readonly bool $exists,
		public readonly ?Note $note,
		public readonly array $highlights,
		public readonly int $latest,
		public readonly array $orphaned = [],
	) {
	}

	public function isOrphaned(Highlight $highlight): bool {
		return in_array($highlight->uuid, $this->orphaned, true);
	}

	public function isPage(): bool {
		return $this->entryId === null;
	}

	/** The site host of the page, or of the article's link. */
	public function host(): string {
		$host = parse_url($this->url, PHP_URL_HOST);
		return is_string($host) ? $host : '';
	}
}
