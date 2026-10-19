<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * A passage the user selected on a FreshRSS article (`entryId` set, with the article snapshot taken when it was
 * created) or on any web page (`entryId` null).
 */
final class Highlight {
	public const SOURCES = ['freshrss', 'page'];
	public const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];

	/**
	 * @param array{quote:array{exact:string,prefix?:string,suffix?:string},position?:array{start:int,end:int}} $selector
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly ?string $entryId,
		public readonly string $url,
		public readonly string $normalizedUrl,
		public readonly string $title,
		public readonly string $entryGuid,
		public readonly string $entryLink,
		public readonly string $entryTitle,
		public readonly string $entryAuthor,
		public readonly int $entryDate,
		public readonly int $feedId,
		public readonly string $feedName,
		public readonly string $feedUrl,
		public readonly string $source,
		public readonly array $selector,
		public readonly string $color,
		public readonly string $note,
		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $deletedAt,
		public readonly bool $labelApplied = false,
		public readonly ?int $labelId = null,
	) {
	}

	public function isDeleted(): bool {
		return $this->deletedAt !== null;
	}

	/** The start offset of the selector's position, or null when it has none. */
	public function positionStart(): ?int {
		return $this->selector['position']['start'] ?? null;
	}

	public function etag(): string {
		return '"' . $this->id . '-' . $this->updatedAt . '"';
	}

	/**
	 * The API representation (also used by the FreshRSS controller actions).
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(bool $articleExists): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'kind' => 'highlight',
			'entry_id' => $this->entryId,
			'greader_item_id' => $this->entryId === null ? null : Api::greaderItemId($this->entryId),
			'url' => $this->url,
			'normalized_url' => $this->normalizedUrl,
			'title' => $this->title === '' ? null : $this->title,
			'source' => $this->source,
			'selector' => $this->selector,
			'color' => $this->color,
			'note' => $this->note === '' || $this->isDeleted() ? null : $this->note,
			'created_at' => $this->createdAt,
			'created_at_iso' => Api::iso($this->createdAt),
			'updated_at' => $this->updatedAt,
			'updated_at_iso' => Api::iso($this->updatedAt),
			'deleted_at' => $this->deletedAt,
			'deleted_at_iso' => $this->deletedAt === null ? null : Api::iso($this->deletedAt),
			'label_applied' => $this->labelApplied,
			'article' => $this->entryId === null ? null : [
				'guid' => $this->entryGuid,
				'url' => $this->entryLink,
				'title' => $this->entryTitle,
				'author' => $this->entryAuthor,
				'published_at' => $this->entryDate,
				'published_at_iso' => Api::iso($this->entryDate),
				'feed' => ['id' => $this->feedId, 'name' => $this->feedName, 'url' => $this->feedUrl],
				'exists' => $articleExists,
			],
		];
	}
}
