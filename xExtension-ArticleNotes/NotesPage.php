<?php

declare(strict_types=1);

namespace ArticleNotes;

/** What the Notes page shows: one page of groups for a filter. */
final class NotesPage {
	/**
	 * @param list<NotesGroup> $groups
	 * @param array<int,string> $feeds feed id => name, for the feed filter
	 */
	public function __construct(
		public readonly NotesFilter $filter,
		public readonly array $groups,
		public readonly int $total,
		public readonly int $page,
		public readonly array $feeds,
	) {
	}

	public function pages(): int {
		return max(1, (int)ceil($this->total / NotesBrowser::PER_PAGE));
	}
}
