<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * One user's URL index: maps the hash of an article's normalized URL (its link and, when it is a URL, its GUID) to the
 * article, because FreshRSS does not index `entry.link`.
 *
 * FreshRSS renumbers new articles when it commits them, so the final ids are unknown to the `entry_before_*` hooks:
 * articles are indexed after insertion by {@see backfill()} (user maintenance, and lazily on lookups), which finds the
 * articles without an index row. Every article has a `source` 0 row (its link; an empty hash when it has no usable
 * URL) and, when its GUID is a different URL, a `source` 1 row.
 */
final class UrlIndex {
	private const SOURCE_LINK = 0;
	private const SOURCE_GUID = 1;

	private readonly string $table;
	private readonly string $entries;
	private readonly string $feeds;

	public function __construct(private readonly \Minz_Pdo $pdo) {
		$this->table = $pdo->prefix() . 'articlenotes_url';
		$this->entries = $pdo->prefix() . 'entry';
		$this->feeds = $pdo->prefix() . 'feed';
	}

	/**
	 * Indexes up to `$limit` articles that have no index row yet, newest first.
	 *
	 * @return int number of articles indexed
	 */
	public function backfill(int $limit): int {
		/** @var list<array<string,string|int|null>> $rows */
		$rows = $this->run(
			'SELECT e.id, e.link, e.guid FROM ' . $this->entries . ' e WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table
				. ' u WHERE u.entry_id=e.id AND u.source=' . self::SOURCE_LINK . ') ORDER BY e.id DESC LIMIT ' . max(0, $limit),
			[],
		)->fetchAll(\PDO::FETCH_ASSOC);
		$indexed = 0;
		foreach ($rows as $row) {
			try {
				$this->index((string)$row['id'], (string)$row['link'], (string)$row['guid']);
				$indexed++;
			} catch (\RuntimeException $exception) {
				// Indexed concurrently by another request (the primary key refused the duplicate): leave it to them.
				\Minz_Log::debug('ArticleNotes URL index: ' . $exception->getMessage());
			}
		}
		return $indexed;
	}

	/** Whether every article has been indexed. */
	public function isComplete(): bool {
		$statement = $this->run(
			'SELECT 1 FROM ' . $this->entries . ' e WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table
				. ' u WHERE u.entry_id=e.id AND u.source=' . self::SOURCE_LINK . ') LIMIT 1',
			[],
		);
		$missing = $statement->fetchColumn() !== false;
		$statement->closeCursor();
		return !$missing;
	}

	/** (Re)writes the index rows of one article. */
	public function index(string $entryId, string $link, string $guid): void {
		$this->forget($entryId);
		$linkUrl = UrlNormalizer::normalize($link);
		$linkHash = $linkUrl === null ? '' : UrlNormalizer::hash($linkUrl);
		$this->insert($entryId, self::SOURCE_LINK, $linkHash, $link, '');
		$guidUrl = UrlNormalizer::normalize($guid);
		if ($guidUrl !== null && UrlNormalizer::hash($guidUrl) !== $linkHash) {
			$this->insert($entryId, self::SOURCE_GUID, UrlNormalizer::hash($guidUrl), $link, $guid);
		}
	}

	/** Drops the index rows of one article (it is indexed again by the next {@see backfill()}). */
	public function forget(string $entryId): void {
		$this->run('DELETE FROM ' . $this->table . ' WHERE entry_id=:entry_id', [':entry_id' => (int)$entryId]);
	}

	/** Drops the rows of the article with this GUID in this feed, e.g. because FreshRSS is updating it (its link may change). */
	public function forgetByGuid(int $feedId, string $guid): void {
		$this->run('DELETE FROM ' . $this->table . ' WHERE entry_id IN (SELECT id FROM ' . $this->entries
			. ' WHERE id_feed=:feed_id AND guid=:guid)', [':feed_id' => $feedId, ':guid' => $guid]);
	}

	/**
	 * Drops the rows of articles FreshRSS has purged.
	 *
	 * @return int number of rows removed
	 */
	public function prune(): int {
		return $this->run('DELETE FROM ' . $this->table . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $this->entries
			. ' e WHERE e.id=' . $this->table . '.entry_id)', [])->rowCount();
	}

	/**
	 * The articles whose link or GUID has the normalized URL hash, most recently published first. Rows that no longer
	 * describe their article (it was updated, or renumbered) are dropped instead of returned.
	 *
	 * @return list<array{entry_id:string,title:string,link:string,guid:string,date:int,feed_id:int,feed_name:string,feed_url:string}>
	 */
	public function find(string $urlHash, int $limit): array {
		/** @var list<array<string,string|int|null>> $rows */
		$rows = $this->run(
			'SELECT DISTINCT e.id, e.title, e.link, e.guid, e.date, e.id_feed, f.name AS feed_name, f.url AS feed_url FROM '
				. $this->table . ' u INNER JOIN ' . $this->entries . ' e ON e.id=u.entry_id LEFT JOIN ' . $this->feeds
				. ' f ON f.id=e.id_feed WHERE u.url_hash=:url_hash AND u.url_hash<>\'\' ORDER BY e.date DESC, e.id DESC LIMIT '
				. max(0, $limit),
			[':url_hash' => $urlHash],
		)->fetchAll(\PDO::FETCH_ASSOC);
		$found = [];
		foreach ($rows as $row) {
			$link = (string)$row['link'];
			$guid = (string)$row['guid'];
			$hashes = array_map(
				static fn (string $url): string => ($normalized = UrlNormalizer::normalize($url)) === null ? '' : UrlNormalizer::hash($normalized),
				[$link, $guid],
			);
			if (!in_array($urlHash, $hashes, true)) {
				$this->forget((string)$row['id']);
				continue;
			}
			$found[] = [
				'entry_id' => (string)$row['id'], 'title' => (string)$row['title'], 'link' => $link, 'guid' => $guid,
				'date' => (int)$row['date'], 'feed_id' => (int)$row['id_feed'],
				'feed_name' => (string)($row['feed_name'] ?? ''), 'feed_url' => (string)($row['feed_url'] ?? ''),
			];
		}
		return $found;
	}

	private function insert(string $entryId, int $source, string $urlHash, string $link, string $guid): void {
		$this->run('INSERT INTO ' . $this->table . ' (entry_id, source, url_hash, link, guid) VALUES (:entry_id, :source, :url_hash, :link, :guid)', [
			':entry_id' => (int)$entryId, ':source' => $source, ':url_hash' => $urlHash, ':link' => $link, ':guid' => $guid,
		]);
	}

	/** @param array<string,int|string> $values */
	private function run(string $sql, array $values): \PDOStatement {
		$statement = $this->pdo->prepare($sql);
		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare URL index query: ' . json_encode($this->pdo->errorInfo()));
		}
		foreach ($values as $name => $value) {
			$statement->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		}
		if (!$statement->execute()) {
			throw new \RuntimeException('URL index query failed: ' . json_encode($statement->errorInfo()));
		}
		return $statement;
	}
}
