<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * A user's note on one article (with the snapshot of the article taken when the note was last saved), or a page note on a
 * web page that is no FreshRSS article (`entryId` null; `entryLink` holds the page URL and `entryTitle` its title).
 */
final class Note {
	public function __construct(
		public readonly int $id,
		public readonly ?string $entryId,
		public readonly string $note,
		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $deletedAt,
		public readonly string $entryGuid,
		public readonly string $entryLink,
		public readonly string $entryTitle,
		public readonly string $entryAuthor,
		public readonly int $entryDate,
		public readonly int $feedId,
		public readonly string $feedName,
		public readonly string $feedUrl,
		public readonly bool $labelApplied,
		/** Id of the label the extension applied to the article (set when `labelApplied`). */
		public readonly ?int $labelId = null,
		/** Normalized {@see UrlNormalizer} form of the article's URL ('' when it has none). */
		public readonly string $normalizedUrl = '',
	) {
	}

	/** The article's URL as FreshRSS knows it: its link, or its GUID when that is the only URL. */
	public function url(): string {
		return $this->entryLink !== '' || UrlNormalizer::normalize($this->entryGuid) === null ? $this->entryLink : $this->entryGuid;
	}

	public function isPageNote(): bool {
		return $this->entryId === null;
	}

	public function isDeleted(): bool {
		return $this->deletedAt !== null;
	}
}
