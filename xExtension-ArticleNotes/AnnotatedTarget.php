<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * An article or web page the user annotated: what {@see AnnotatedTargets} lists. Article snapshot fields come from the
 * live annotations of the target and are empty for a page.
 */
final class AnnotatedTarget {
	/**
	 * @param list<string> $colors distinct colors of the live highlights, in the palette's order
	 */
	public function __construct(
		/** Article id, null for a page. */
		public readonly ?string $entryId,
		public readonly string $url,
		public readonly string $normalizedUrl,
		public readonly string $title,
		public readonly string $guid,
		public readonly string $author,
		public readonly int $entryDate,
		public readonly int $feedId,
		public readonly string $feedName,
		public readonly string $feedUrl,
		public readonly int $highlightCount,
		public readonly array $colors,
		public readonly int $firstAt,
		public readonly int $lastAt,
		/** Hash of the normalized URL, the key of a page (empty for an article). */
		public readonly string $pageHash,
		public readonly ?Note $note = null,
	) {
	}

	public function isPage(): bool {
		return $this->entryId === null;
	}
}
