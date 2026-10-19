<?php

declare(strict_types=1);

namespace ArticleNotes;

/** An article found for a URL, with the user's live note on it (if any). */
final class UrlMatch {
	public function __construct(
		public readonly string $entryId,
		public readonly string $title,
		public readonly string $url,
		public readonly int $publishedAt,
		public readonly int $feedId,
		public readonly string $feedName,
		public readonly string $feedUrl,
		public readonly ?Note $note,
	) {
	}
}
