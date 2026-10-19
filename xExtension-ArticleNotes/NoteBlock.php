<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Renders a note as the HTML block shown under an article. Everything user-controlled is escaped. */
final class NoteBlock {
	public static function html(Note $note): string {
		$text = htmlspecialchars($note->note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$time = '<time datetime="' . htmlspecialchars(date('c', $note->updatedAt), ENT_QUOTES, 'UTF-8') . '">'
			. htmlspecialchars(date('Y-m-d H:i', $note->updatedAt), ENT_QUOTES, 'UTF-8') . '</time>';
		return '<section class="articlenotes-note" data-articlenotes-text="' . $text . '">'
			. '<h4 class="articlenotes-note-title">' . htmlspecialchars(_t('ext.article_notes.note.title'), ENT_QUOTES, 'UTF-8') . '</h4>'
			. '<div class="articlenotes-note-text">' . nl2br($text, false) . '</div>'
			. '<p class="articlenotes-note-meta">' . sprintf(htmlspecialchars(_t('ext.article_notes.note.updated'), ENT_QUOTES, 'UTF-8'), $time) . '</p>'
			. '</section>';
	}
}
