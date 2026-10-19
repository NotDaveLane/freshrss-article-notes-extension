<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * Applies the user's "Annotated" label to an article when it gains its first article note or highlight, and takes back
 * what it applied when the article has neither any more (never a label the user added).
 */
final class LabelApplier {
	public function __construct(private readonly string $user, private readonly Settings $settings) {
	}

	/** Wires the label behavior to a note service. */
	public function listen(NoteService $service): void {
		$service->onSaved($this->noteSaved(...));
		$service->onDeleted($this->noteDeleted(...));
	}

	/** Wires the label behavior to a highlight service. */
	public function listenHighlights(HighlightService $service): void {
		$service->onSaved($this->highlightSaved(...));
		$service->onDeleted($this->highlightDeleted(...));
	}

	/** Labels the article of a note that was just created or revived, when the user wants it. */
	public function noteSaved(Note $note, bool $created): void {
		if ($note->entryId === null || !$created || !$this->settings->labelEnabled()) {
			return;
		}
		$entryId = $note->entryId;
		if ($this->highlights()->countLiveForEntry($entryId) > 0) {
			return;	// not the article's first annotation
		}
		$this->apply($entryId, fn (int $tagId) => $this->notes()->setAppliedLabel($entryId, $tagId));
	}

	/** Labels the article of a highlight that was just created, when the user wants it. */
	public function highlightSaved(Highlight $highlight, bool $created): void {
		if ($highlight->entryId === null || !$created || !$this->settings->labelEnabled()) {
			return;
		}
		$entryId = $highlight->entryId;
		if ($this->notes()->find($entryId) !== null || $this->highlights()->countLiveForEntry($entryId) > 1) {
			return;	// not the article's first annotation
		}
		$this->apply($entryId, fn (int $tagId) => $this->highlights()->setAppliedLabel($highlight->uuid, $tagId));
	}

	/** Removes the label the extension applied to the article of a deleted note, unless a highlight keeps it. */
	public function noteDeleted(Note $note): void {
		if ($note->entryId !== null) {
			$this->release($note->entryId);
		}
	}

	/** Removes the label the extension applied to the article of a deleted highlight, unless a note or highlight keeps it. */
	public function highlightDeleted(Highlight $highlight): void {
		if ($highlight->entryId !== null) {
			$this->release($highlight->entryId);
		}
	}

	/** @param \Closure(int):void $record stores the label id on the annotation that triggered the labelling */
	private function apply(string $entryId, \Closure $record): void {
		$tags = \FreshRSS_Factory::createTagDao($this->user);
		$name = $this->settings->labelName();
		$tag = $tags->searchByName($name);
		$tagId = $tag?->id() ?? $tags->addTag(['name' => $name]);
		if ($tagId === false) {
			throw new \RuntimeException('Cannot create the label ' . $name);
		}
		foreach ($tags->getTagsForEntry($entryId) as $entryTag) {
			if ($entryTag['id'] === $tagId && $entryTag['checked']) {
				return;	// labelled by the user: not ours to remove later (and re-tagging fails on PostgreSQL with FreshRSS 1.27)
			}
		}
		if (!$tags->tagEntry($tagId, $entryId)) {
			throw new \RuntimeException('Cannot label the article ' . $entryId);
		}
		$record($tagId);
	}

	/** Takes back the labels the extension applied once the article has neither a live note nor a live highlight. */
	private function release(string $entryId): void {
		$notes = $this->notes();
		$highlights = $this->highlights();
		if ($notes->find($entryId) !== null || $highlights->countLiveForEntry($entryId) > 0) {
			return;
		}
		$labelIds = $highlights->appliedLabelIds($entryId);
		$note = $notes->find($entryId, true);
		if ($note !== null && $note->labelApplied && $note->labelId !== null) {
			$labelIds[] = $note->labelId;
		}
		$tags = \FreshRSS_Factory::createTagDao($this->user);
		foreach (array_unique($labelIds) as $labelId) {
			$tags->tagEntry($labelId, $entryId, false);
		}
		$notes->setAppliedLabel($entryId, null);
		$highlights->clearAppliedLabels($entryId);
	}

	private function notes(): NoteDAO {
		return new NoteDAO((new Storage($this->user))->pdo());
	}

	private function highlights(): HighlightDAO {
		return new HighlightDAO((new Storage($this->user))->pdo());
	}
}
