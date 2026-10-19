<?php

declare(strict_types=1);

use ArticleNotes\Highlight;
use ArticleNotes\Note;
use ArticleNotes\NotesExport;
use ArticleNotes\NotesGroup;
use ArticleNotes\WebAnnotation;
use PHPUnit\Framework\TestCase;

/** Markdown and W3C Web Annotation exports of a known dataset. */
final class NotesExportTest extends TestCase {
	private static function note(int $id, ?string $entryId, string $text, string $link, string $title): Note {
		return new Note($id, $entryId, $text, 1756717200, 1756720800, null, 'guid', $link, $title, 'Author', 1756600000, 7, 'Alpha Feed', 'http://feed/', false);
	}

	/** @param array{quote:array{exact:string,prefix?:string,suffix?:string},position?:array{start:int,end:int}} $selector */
	private static function highlight(int $id, ?string $entryId, string $url, array $selector, string $color = 'yellow', string $note = ''): Highlight {
		return new Highlight($id, '3f1c7d52-0000-4000-8000-00000000000' . $id, $entryId, $url, $url, '', '', '', '', '', 0, 0, '', '',
			'freshrss', $selector, $color, $note, 1756717300, 1756717400, null);
	}

	/** @return list<NotesGroup> */
	private static function dataset(): array {
		$article = new NotesGroup('42', 'Rust [async] & _more_', 'https://example.com/a(1)', 'Alpha Feed', 1756600000, true,
			self::note(1, '42', "Line one\nLine two <b>x</b>", 'https://example.com/a(1)', 'Rust [async] & _more_'),
			[
				self::highlight(2, '42', 'https://example.com/a(1)', ['quote' => ['exact' => 'ownership'], 'position' => ['start' => 0, 'end' => 9]]),
				self::highlight(1, '42', 'https://example.com/a(1)',
					['quote' => ['exact' => 'zero cost', 'prefix' => 'the ', 'suffix' => ' abstractions'], 'position' => ['start' => 4, 'end' => 13]],
					'green', 'great'),
			], 1756720800);
		$page = new NotesGroup(null, 'Example page', 'https://example.org/p', '', 0, true,
			self::note(2, null, 'page note', 'https://example.org/p', 'Example page'), [], 1756720800);
		$bare = new NotesGroup(null, '', 'https://bare.example.net/', '', 0, true, null,
			[self::highlight(3, null, 'https://bare.example.net/', ['quote' => ['exact' => 'bare quote']], 'pink')], 1756720800);
		return [$article, $page, $bare];
	}

	public function testMarkdown(): void {
		$expected = <<<'MD'
			# Notes

			## [Rust \[async\] & \_more\_](https://example.com/a%281%29)

			*Alpha Feed · 2025-08-31*

			Line one
			Line two <b>x</b>

			> ownership

			> zero cost

			great

			## [Example page](https://example.org/p)

			*example.org*

			page note

			## [https://bare.example.net/](https://bare.example.net/)

			*bare.example.net*

			> bare quote

			MD;
		self::assertSame($expected, NotesExport::markdown(self::dataset()));
	}

	public function testMarkdownOfNothingIsJustTheHeading(): void {
		self::assertSame("# Notes\n", NotesExport::markdown([]));
	}

	/** @return array{'@context':string,type:string,label:string,total:int,first:array{type:string,items:list<array<string,mixed>>}} */
	private static function collection(): array {
		$decoded = json_decode(NotesExport::webAnnotations(self::dataset()), true);
		self::assertIsArray($decoded);
		/** @var array{'@context':string,type:string,label:string,total:int,first:array{type:string,items:list<array<string,mixed>>}} $decoded */
		return $decoded;
	}

	/**
	 * @return list<array{id:string,type:string,motivation:string|list<string>,created:string,modified:string,
	 *   body?:array{type:string,value:string,format:string},
	 *   target:array{source:string,selector?:list<array{type:string,exact?:string,prefix?:string,suffix?:string,start?:int,end?:int}>}}>
	 */
	private static function items(): array {
		/** @var list<array{id:string,type:string,motivation:string|list<string>,created:string,modified:string,body?:array{type:string,value:string,format:string},target:array{source:string,selector?:list<array{type:string,exact?:string,prefix?:string,suffix?:string,start?:int,end?:int}>}}> $items */
		$items = self::collection()['first']['items'];
		return $items;
	}

	public function testWebAnnotationCollection(): void {
		$collection = self::collection();
		self::assertSame(WebAnnotation::CONTEXT, $collection['@context']);
		self::assertSame('AnnotationCollection', $collection['type']);
		self::assertSame(5, $collection['total']);
		self::assertSame('AnnotationPage', $collection['first']['type']);
		self::assertCount(5, $collection['first']['items']);
		foreach ($collection['first']['items'] as $item) {
			self::assertArrayNotHasKey('@context', $item, 'the collection carries the context');
		}
	}

	/** Every annotation has what the Web Annotation Data Model requires: @context, an IRI id, type Annotation, a target. */
	public function testEveryAnnotationHasTheRequiredFields(): void {
		foreach (self::items() as $annotation) {
			$standalone = ['@context' => WebAnnotation::CONTEXT] + $annotation;
			self::assertSame('Annotation', $standalone['type']);
			self::assertMatchesRegularExpression('/^[a-z][a-z0-9+.-]*:\S+$/i', $standalone['id']);
			self::assertNotFalse(filter_var($standalone['target']['source'], FILTER_VALIDATE_URL));
			self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $standalone['created']);
			$motivations = is_array($standalone['motivation']) ? $standalone['motivation'] : [$standalone['motivation']];
			self::assertSame([], array_diff($motivations, ['commenting', 'highlighting']));
			if (isset($standalone['body'])) {
				self::assertSame('TextualBody', $standalone['body']['type']);
				self::assertNotSame('', $standalone['body']['value']);
			}
			foreach ($standalone['target']['selector'] ?? [] as $selector) {
				self::assertContains($selector['type'], ['TextQuoteSelector', 'TextPositionSelector']);
				if ($selector['type'] === 'TextQuoteSelector') {
					self::assertArrayHasKey('exact', $selector);
					self::assertNotSame('', $selector['exact']);
				} else {
					self::assertArrayHasKey('start', $selector);
					self::assertArrayHasKey('end', $selector);
				}
			}
		}
	}

	public function testAnnotationsForAKnownDataset(): void {
		$items = self::items();
		self::assertSame([
			'id' => 'urn:articlenotes:note:1',
			'type' => 'Annotation',
			'motivation' => 'commenting',
			'created' => '2025-09-01T09:00:00+00:00',
			'modified' => '2025-09-01T10:00:00+00:00',
			'body' => ['type' => 'TextualBody', 'value' => "Line one\nLine two <b>x</b>", 'format' => 'text/plain'],
			'target' => ['source' => 'https://example.com/a(1)'],
		], $items[0]);
		self::assertSame([
			'id' => 'urn:uuid:3f1c7d52-0000-4000-8000-000000000002',
			'type' => 'Annotation',
			'motivation' => 'highlighting',
			'created' => '2025-09-01T09:01:40+00:00',
			'modified' => '2025-09-01T09:03:20+00:00',
			'target' => ['source' => 'https://example.com/a(1)', 'selector' => [
				['type' => 'TextQuoteSelector', 'exact' => 'ownership'],
				['type' => 'TextPositionSelector', 'start' => 0, 'end' => 9],
			]],
		], $items[1]);
		self::assertSame(['highlighting', 'commenting'], $items[2]['motivation']);
		self::assertSame([
			['type' => 'TextQuoteSelector', 'exact' => 'zero cost', 'prefix' => 'the ', 'suffix' => ' abstractions'],
			['type' => 'TextPositionSelector', 'start' => 4, 'end' => 13],
		], $items[2]['target']['selector'] ?? null);
		self::assertSame('great', $items[2]['body']['value'] ?? null);
		self::assertSame('urn:articlenotes:note:2', $items[3]['id']);
		self::assertSame('https://example.org/p', $items[3]['target']['source']);
	}

	public function testASingleAnnotationKeepsItsOwnContext(): void {
		$annotation = WebAnnotation::fromHighlight(self::highlight(1, null, 'https://example.org/', ['quote' => ['exact' => 'x']]));
		self::assertSame(WebAnnotation::CONTEXT, $annotation['@context']);
	}
}
