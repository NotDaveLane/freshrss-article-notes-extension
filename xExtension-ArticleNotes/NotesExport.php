<?php

declare(strict_types=1);

namespace ArticleNotes;

/** Renders groups of annotations (see {@see NotesBrowser}) as files: Markdown, or W3C Web Annotation JSON-LD. */
final class NotesExport {
	/** @param list<NotesGroup> $groups */
	public static function markdown(array $groups, string $heading = 'Notes'): string {
		$out = '# ' . self::inline($heading) . "\n";
		foreach ($groups as $group) {
			$title = $group->title !== '' ? $group->title : ($group->url !== '' ? $group->url : '#' . $group->entryId);
			$link = self::inline($title);
			if ($group->url !== '') {
				$link = '[' . $link . '](' . self::destination($group->url) . ')';
			}
			$out .= "\n## " . $link . "\n";
			$meta = array_filter([
				$group->isPage() ? $group->host() : $group->feedName,
				$group->date > 0 ? gmdate('Y-m-d', $group->date) : '',
			], static fn (string $part): bool => $part !== '');
			if ($meta !== []) {
				$out .= "\n*" . self::inline(implode(' · ', $meta)) . "*\n";
			}
			if ($group->note !== null) {
				$out .= "\n" . trim($group->note->note) . "\n";
			}
			foreach ($group->highlights as $highlight) {
				$out .= "\n" . self::quote($highlight->selector['quote']['exact']) . "\n";
				if ($highlight->note !== '') {
					$out .= "\n" . trim($highlight->note) . "\n";
				}
			}
		}
		return $out;
	}

	/** @param list<NotesGroup> $groups */
	public static function webAnnotations(array $groups): string {
		$annotations = [];
		foreach ($groups as $group) {
			if ($group->note !== null) {
				$annotations[] = WebAnnotation::fromNote($group->note);
			}
			foreach ($group->highlights as $highlight) {
				$annotations[] = WebAnnotation::fromHighlight($highlight);
			}
		}
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		return json_encode(WebAnnotation::collection($annotations), $flags) . "\n";
	}

	/** Text for a heading, link label or emphasis: one line, Markdown punctuation escaped. */
	private static function inline(string $text): string {
		return preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', trim((string)preg_replace('/\s+/', ' ', $text))) ?? $text;
	}

	/** A link destination that cannot end the link early. */
	private static function destination(string $url): string {
		return strtr(trim($url), [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', "\n" => '', "\r" => '']);
	}

	private static function quote(string $text): string {
		$lines = preg_split('/\R/', trim($text));
		return implode("\n", array_map(static fn (string $line): string => rtrim('> ' . $line), $lines === false ? [''] : $lines));
	}
}
