<?php

declare(strict_types=1);

namespace ArticleNotes;

/** What an annotation keeps of its article, so it stays meaningful after FreshRSS purges the article. */
final class ArticleSnapshot {
	public function __construct(
		public readonly string $entryId,
		public readonly string $guid,
		public readonly string $link,
		public readonly string $title,
		public readonly string $author,
		public readonly int $date,
		public readonly int $feedId,
		public readonly string $feedName,
		public readonly string $feedUrl,
		/** Normalized {@see UrlNormalizer} form of the article's URL ('' when it has none). */
		public readonly string $normalizedUrl,
	) {
	}

	/** The snapshot of a stored article, or null when the user has no such article. */
	public static function load(string $user, string $entryId): ?self {
		$entry = \FreshRSS_Factory::createEntryDao($user)->searchById($entryId);
		if ($entry === null) {
			return null;
		}
		$feed = \FreshRSS_Factory::createFeedDao($user)->searchById($entry->feedId());
		return new self(
			$entry->id(), $entry->guid(), $entry->link(true), $entry->title(), implode(', ', $entry->authors()), (int)$entry->date(true),
			$entry->feedId(), $feed === null ? '' : $feed->name(true), $feed === null ? '' : $feed->url(false),
			UrlNormalizer::forEntry($entry->link(true), $entry->guid()) ?? '',
		);
	}

	/** The article's URL as FreshRSS knows it: its link, or its GUID when that is the only URL. */
	public function url(): string {
		return $this->link !== '' || UrlNormalizer::normalize($this->guid) === null ? $this->link : $this->guid;
	}
}
