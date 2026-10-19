<?php

declare(strict_types=1);

namespace ArticleNotes;

/** What the Notes page (and its exports) shows: filters that combine, kept in the query string. */
final class NotesFilter {
	public const KINDS = ['article', 'page', 'highlight'];
	public const MAX_QUERY_LENGTH = 200;

	/**
	 * @param string $query text searched in notes, quotes and titles ('' for no search)
	 * @param string $kind one of {@see KINDS}, '' for all
	 * @param string $color one of {@see Highlight::COLORS}, '' for all (only highlights have a color)
	 * @param int $feedId a feed id, 0 for all (only articles have a feed)
	 * @param bool $hasNote only annotations with a note (every note has one; highlights may not)
	 */
	public function __construct(
		public readonly string $query = '',
		public readonly string $kind = '',
		public readonly string $color = '',
		public readonly int $feedId = 0,
		public readonly bool $hasNote = false,
	) {
	}

	/**
	 * Reads the filters from request parameters; unknown values are ignored.
	 *
	 * @param array<string,string> $params
	 */
	public static function fromParams(array $params): self {
		$kind = $params['kind'] ?? '';
		$color = $params['color'] ?? '';
		$feed = $params['feed'] ?? '';
		$query = $params['q'] ?? '';
		return new self(
			mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH, 'UTF-8'),
			in_array($kind, self::KINDS, true) ? $kind : '',
			in_array($color, Highlight::COLORS, true) ? $color : '',
			ctype_digit($feed) && strlen($feed) < 10 ? (int)$feed : 0,
			($params['has_note'] ?? '') === '1',
		);
	}

	/**
	 * The non-default filters as query string parameters (the inverse of {@see fromParams()}).
	 *
	 * @return array<string,string>
	 */
	public function toParams(): array {
		return array_filter([
			'q' => $this->query,
			'kind' => $this->kind,
			'color' => $this->color,
			'feed' => $this->feedId === 0 ? '' : (string)$this->feedId,
			'has_note' => $this->hasNote ? '1' : '',
		], static fn (string $value): bool => $value !== '');
	}

	public function isEmpty(): bool {
		return $this->toParams() === [];
	}
}
