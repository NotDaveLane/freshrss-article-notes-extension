<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Finds the FreshRSS articles (and the notes on purged articles) that a web page URL stands for. */
final class UrlLookup {
	public const MAX_MATCHES = 20;
	/** Articles indexed per lookup while the index is still being built. */
	public const LAZY_BATCH = 500;

	public function __construct(private readonly NoteService $notes) {
	}

	/**
	 * Matches for a normalized URL, most recently published first: articles from the URL index plus articles that have
	 * a note at that URL (which covers articles FreshRSS purged since).
	 *
	 * @return array{index_complete:bool,matches:list<UrlMatch>}
	 */
	public function find(string $normalizedUrl): array {
		$index = $this->notes->urlIndex();
		$index->backfill(self::LAZY_BATCH);
		$hash = UrlNormalizer::hash($normalizedUrl);

		$matches = [];
		foreach ($index->find($hash, self::MAX_MATCHES) as $row) {
			$url = $row['link'] !== '' ? $row['link'] : $row['guid'];
			$matches[$row['entry_id']] = new UrlMatch($row['entry_id'], $row['title'], $url, $row['date'],
				$row['feed_id'], $row['feed_name'], $row['feed_url'], $this->notes->get($row['entry_id']));
		}
		foreach ($this->notes->findByUrl($normalizedUrl, self::MAX_MATCHES) as $note) {
			$matches[$note->entryId] ??= new UrlMatch($note->entryId, $note->entryTitle, $note->url(), $note->entryDate,
				$note->feedId, $note->feedName, $note->feedUrl, $note);
		}

		$matches = array_values($matches);
		usort($matches, static fn (UrlMatch $a, UrlMatch $b): int => [$b->publishedAt, $b->entryId] <=> [$a->publishedAt, $a->entryId]);
		return ['index_complete' => $index->isComplete(), 'matches' => array_slice($matches, 0, self::MAX_MATCHES)];
	}
}
