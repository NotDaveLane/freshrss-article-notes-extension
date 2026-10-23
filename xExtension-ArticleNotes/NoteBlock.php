<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * Renders a note as the HTML block shown under an article; the block is focusable and opens the note editor when clicked
 * or tapped. It is a region named by its heading, described by a hidden "Edit your note" hint. Everything user-controlled is
 * escaped.
 */
final class NoteBlock {
	public static function html(Note $note): string {
		$text = htmlspecialchars($note->note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$time = '<time datetime="' . htmlspecialchars(date('c', $note->updatedAt), ENT_QUOTES, 'UTF-8') . '">'
			. htmlspecialchars(timestamptodate($note->updatedAt), ENT_QUOTES, 'UTF-8') . '</time>';
		$hint = htmlspecialchars(_t('ext.article_notes.note.edit_hint'), ENT_QUOTES, 'UTF-8');
		$titleId = 'articlenotes-note-title-' . $note->id;
		$hintId = 'articlenotes-note-hint-' . $note->id;
		return '<section class="articlenotes-note" data-articlenotes-text="' . $text . '" tabindex="0" title="' . $hint
			. '" aria-labelledby="' . $titleId . '" aria-describedby="' . $hintId . '">'
			. '<h4 class="articlenotes-note-title" id="' . $titleId . '">' . htmlspecialchars(_t('ext.article_notes.note.title'), ENT_QUOTES, 'UTF-8') . '</h4>'
			. '<div class="articlenotes-note-text">' . nl2br($text, false) . '</div>'
			. '<p class="articlenotes-note-meta">' . sprintf(htmlspecialchars(_t('ext.article_notes.note.updated'), ENT_QUOTES, 'UTF-8'), $time) . '</p>'
			. '<span id="' . $hintId . '" hidden>' . $hint . '</span>'
			. '</section>';
	}
}
