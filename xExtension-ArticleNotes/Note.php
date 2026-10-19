<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A user's note on one article, with the snapshot of the article taken when the note was last saved. */
final class Note {
	public function __construct(
		public readonly int $id,
		public readonly string $entryId,
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
	) {
	}

	public function isDeleted(): bool {
		return $this->deletedAt !== null;
	}
}
