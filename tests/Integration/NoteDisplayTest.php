<?php

declare(strict_types=1);

use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class NoteDisplayTest extends TestCase {
	private ArticleNotesExtension $extension;
	/** @var list<int> */
	private array $feedIds = [];

	#[\Override]
	protected function setUp(): void {
		Minz_User::change(TestEnvironment::USER);
		cliInitUser(TestEnvironment::USER);
		Minz_Translate::registerPath(dirname(__DIR__, 2) . '/xExtension-ArticleNotes/i18n');
		Minz_Translate::reset('en');
		Minz_Session::_param('passwordHash', FreshRSS_Context::userConf()->passwordHash);
		FreshRSS_Auth::giveAccess();
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		$this->extension = new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $extension->getPath(), 'type' => 'system',
		]);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->feedIds as $feedId) {
			FreshRSS_Factory::createFeedDao()->deleteFeed($feedId);	// also removes its entries
		}
		$this->feedIds = [];
	}

	private function entry(): FreshRSS_Entry {
		$feed = new FreshRSS_Feed('http://fixtures/display-feed.xml', false);
		$feed->_name('Display Feed');
		$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		$feedId = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
		self::assertIsInt($feedId);
		$this->feedIds[] = $feedId;
		$id = (string)(1720000000000000 + random_int(0, 999999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'guid-' . $id, 'title' => 'Display', 'author' => 'A', 'content' => '<p>Body</p>',
			'link' => 'http://fixtures/articles/' . $id, 'date' => 1756717200, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => $feedId, 'tags' => '',
		], false));
		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		self::assertNotNull($entry);
		return $entry;
	}

	public function testNoteIsAppendedEscapedWithLineBreaksAndTime(): void {
		$entry = $this->entry();
		$note = $this->extension->noteService()->save($entry->id(), "<b>bold</b> & \"quoted\"\nline two");
		self::assertNotNull($note);
		$shown = $this->extension->appendNote($entry);
		$content = $shown->content(false);
		self::assertStringStartsWith('<p>Body</p>', $content);
		self::assertStringContainsString('Your note', $content);
		self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;<br>', $content);
		self::assertStringNotContainsString('<b>', $content);
		self::assertMatchesRegularExpression('#Last updated <time datetime="[^"]+">' . preg_quote(timestamptodate($note->updatedAt), '#') . '</time>#', $content);
		self::assertDoesNotMatchRegularExpression('#>\d{4}-\d\d-\d\d \d\d:\d\d</time>#', $content);
		self::assertStringNotContainsString('Tap', $content);
		self::assertStringNotContainsString('aria-label=', $content);
		self::assertMatchesRegularExpression('#<section class="articlenotes-note" [^>]*tabindex="0" title="Edit your note"'
			. ' aria-labelledby="articlenotes-note-title-(\\d+)" aria-describedby="articlenotes-note-hint-\\1">'
			. '<h4 class="articlenotes-note-title" id="articlenotes-note-title-\\1">Your note</h4>#', $content);
		self::assertMatchesRegularExpression('#<span id="articlenotes-note-hint-\\d+" hidden>Edit your note</span></section>$#', $content);
	}

	public function testArticleWithoutNoteIsUntouched(): void {
		$entry = $this->entry();
		self::assertSame('<p>Body</p>', $this->extension->appendNote($entry)->content(false));
	}

	public function testDeletedNoteIsNotShown(): void {
		$entry = $this->entry();
		$service = $this->extension->noteService();
		$service->save($entry->id(), 'gone soon');
		$service->delete($entry->id());
		self::assertSame('<p>Body</p>', $this->extension->appendNote($entry)->content(false));
	}

	public function testJsVarsExposeSettingsAndTranslations(): void {
		$vars = $this->extension->jsVars(['other' => 1]);
		self::assertSame(1, $vars['other']);
		$config = $vars['ArticleNotes'];
		self::assertIsArray($config);
		self::assertSame(20000, $config['maxLength']);
		self::assertIsArray($config['i18n']);
		self::assertSame('Note', $config['i18n']['action']);
	}

	public function testJsVarsExposeTheHighlightUi(): void {
		$config = $this->extension->jsVars([])['ArticleNotes'];
		self::assertIsArray($config);
		self::assertIsString($config['highlightsUrl']);
		self::assertStringContainsString('a=highlights', $config['highlightsUrl']);
		self::assertIsString($config['highlightCreateUrl']);
		self::assertIsString($config['highlightUpdateUrl']);
		self::assertIsString($config['highlightDeleteUrl']);
		self::assertIsString($config['anchoringUrl']);
		self::assertStringContainsString('anchoring.js', $config['anchoringUrl']);
		self::assertStringNotContainsString('&amp;', $config['anchoringUrl']);
		self::assertSame(['yellow', 'green', 'blue', 'pink', 'purple'], $config['colors']);
		self::assertSame(2000, $config['maxQuoteLength']);
		self::assertIsArray($config['i18n']);
		foreach (['highlight', 'addNote', 'tooLong', 'panelTitle', 'orphan', 'jumpTo', 'noteIndicator', 'colorYellow', 'colorPurple',
			'popoverTitle', 'highlightFailed', 'close'] as $key) {
			self::assertIsString($config['i18n'][$key] ?? null, $key);
			self::assertNotSame('', $config['i18n'][$key], $key);
		}
	}
}
