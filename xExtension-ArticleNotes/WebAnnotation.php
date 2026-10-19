<?php

declare(strict_types=1);

namespace ArticleNotes;

/**
 * Maps notes and highlights to W3C Web Annotation JSON-LD (https://www.w3.org/TR/annotation-model/): one `Annotation` per
 * note or highlight, the annotated URL as the target's `source`, `TextQuoteSelector` / `TextPositionSelector` for highlights.
 */
final class WebAnnotation {
	public const CONTEXT = 'http://www.w3.org/ns/anno.jsonld';

	/**
	 * An article note or a page note.
	 *
	 * @return array<string,mixed>
	 */
	public static function fromNote(Note $note): array {
		$source = $note->url() !== '' ? $note->url() : 'urn:freshrss:entry:' . ($note->entryId ?? $note->id);
		return self::annotation(
			'urn:articlenotes:note:' . $note->id, 'commenting', $note->note, $note->createdAt, $note->updatedAt, ['source' => $source],
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function fromHighlight(Highlight $highlight): array {
		$fallback = $highlight->entryLink !== '' ? $highlight->entryLink : 'urn:freshrss:entry:' . $highlight->entryId;
		$source = $highlight->url !== '' ? $highlight->url : $fallback;
		$quote = $highlight->selector['quote'];
		$selectors = [['type' => 'TextQuoteSelector', 'exact' => $quote['exact']]];
		foreach (['prefix', 'suffix'] as $context) {
			if (($quote[$context] ?? '') !== '') {
				$selectors[0][$context] = $quote[$context];
			}
		}
		if (isset($highlight->selector['position'])) {
			$position = $highlight->selector['position'];
			$selectors[] = ['type' => 'TextPositionSelector', 'start' => $position['start'], 'end' => $position['end']];
		}
		return self::annotation(
			'urn:uuid:' . $highlight->uuid, $highlight->note === '' ? 'highlighting' : ['highlighting', 'commenting'], $highlight->note,
			$highlight->createdAt, $highlight->updatedAt, ['source' => $source, 'selector' => $selectors],
		);
	}

	/**
	 * A collection holding the given annotations (as {@see fromNote()} and {@see fromHighlight()} return them).
	 *
	 * @param list<array<string,mixed>> $annotations
	 * @return array<string,mixed>
	 */
	public static function collection(array $annotations, string $label = 'ArticleNotes'): array {
		foreach ($annotations as &$annotation) {
			unset($annotation['@context']);	// the collection's context applies
		}
		return [
			'@context' => self::CONTEXT,
			'type' => 'AnnotationCollection',
			'label' => $label,
			'total' => count($annotations),
			'first' => ['type' => 'AnnotationPage', 'items' => $annotations],
		];
	}

	/**
	 * @param string|list<string> $motivation
	 * @param array<string,mixed> $target
	 * @return array<string,mixed>
	 */
	private static function annotation(string $id, string|array $motivation, string $text, int $created, int $modified, array $target): array {
		$annotation = [
			'@context' => self::CONTEXT,
			'id' => $id,
			'type' => 'Annotation',
			'motivation' => $motivation,
			'created' => Api::iso($created),
			'modified' => Api::iso($modified),
		];
		if ($text !== '') {
			$annotation['body'] = ['type' => 'TextualBody', 'value' => $text, 'format' => 'text/plain'];
		}
		$annotation['target'] = $target;
		return $annotation;
	}
}
