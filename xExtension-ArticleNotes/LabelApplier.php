<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Applies the user's "Annotated" label to annotated articles and takes back what it applied. */
final class LabelApplier {
	public function __construct(private readonly string $user, private readonly Settings $settings) {
	}

	/** Wires the label behavior to a note service. */
	public function listen(NoteService $service): void {
		$service->onSaved($this->noteSaved(...));
		$service->onDeleted($this->noteDeleted(...));
	}

	/** Labels the article of a note that was just created or revived, when the user wants it. */
	public function noteSaved(Note $note, bool $created): void {
		if ($note->entryId === null || !$created || !$this->settings->labelEnabled()) {
			return;
		}
		$tags = \FreshRSS_Factory::createTagDao($this->user);
		$name = $this->settings->labelName();
		$tag = $tags->searchByName($name);
		$tagId = $tag?->id() ?? $tags->addTag(['name' => $name]);
		if ($tagId === false) {
			throw new \RuntimeException('Cannot create the label ' . $name);
		}
		foreach ($tags->getTagsForEntry($note->entryId) as $entryTag) {
			if ($entryTag['id'] === $tagId && $entryTag['checked']) {
				return;	// labelled by the user: not ours to remove later (and re-tagging fails on PostgreSQL with FreshRSS 1.27)
			}
		}
		if (!$tags->tagEntry($tagId, $note->entryId)) {
			throw new \RuntimeException('Cannot label the article ' . $note->entryId);
		}
		$this->dao()->setAppliedLabel($note->entryId, $tagId);
	}

	/** Removes the label the extension applied to the article of a deleted note (never one the user added). */
	public function noteDeleted(Note $note): void {
		if ($note->entryId === null || !$note->labelApplied) {
			return;
		}
		if ($note->labelId !== null) {
			\FreshRSS_Factory::createTagDao($this->user)->tagEntry($note->labelId, $note->entryId, false);
		}
		$this->dao()->setAppliedLabel($note->entryId, null);
	}

	private function dao(): NoteDAO {
		return new NoteDAO((new Storage($this->user))->pdo());
	}
}
