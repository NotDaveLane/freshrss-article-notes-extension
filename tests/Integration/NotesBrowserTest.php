<?php

declare(strict_types=1);

use ArticleNotes\NotesBrowser;
use ArticleNotes\NotesFilter;
use ArticleNotes\NotesGroup;
use ArticleNotesTests\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** The queries behind the Notes page: grouping, search, filters, pagination and per-user isolation. */
final class NotesBrowserTest extends TestCase {
	private const USER = 'nbuser';
	private const OTHER = 'nbother';

	private static int $sequence = 0;
	/** @var array<string,int> */
	private static array $feedIds = [];
	/** @var array<string,string> */
	private static array $entries = [];

	#[\Override]
	public static function setUpBeforeClass(): void {
		self::useUser(TestEnvironment::USER);
		foreach ([self::USER, self::OTHER] as $user) {
			self::assertTrue(FreshRSS_user_Controller::createUser($user, null, 'web-password-123', ['language' => 'en'], false));
			self::useUser($user);
			foreach (['alpha' => 'Alpha Feed', 'beta' => 'Beta Feed'] as $key => $name) {
				$feed = new FreshRSS_Feed('http://fixtures/nb-' . $key . '-' . $user . '.xml', false);
				$feed->_name($name);
				$feed->_categoryId(FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
				$id = FreshRSS_Factory::createFeedDao()->addFeedObject($feed);
				self::assertIsInt($id);
				self::$feedIds[$user . $key] = $id;
			}
		}
		self::seed(self::USER);
		self::seed(self::OTHER, true);
		self::useUser(TestEnvironment::USER);
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		self::useUser(TestEnvironment::USER);
		foreach ([self::USER, self::OTHER] as $user) {
			if (FreshRSS_user_Controller::userExists($user)) {
				FreshRSS_user_Controller::deleteUser($user);
			}
		}
	}

	#[\Override]
	protected function tearDown(): void {
		self::useUser(TestEnvironment::USER);
	}

	private static function useUser(string $user): void {
		Minz_User::change($user);
		cliInitUser($user);
	}

	private static function extension(): ArticleNotesExtension {
		$extension = Minz_ExtensionManager::findExtension('ArticleNotes');
		self::assertInstanceOf(ArticleNotesExtension::class, $extension);
		return new ArticleNotesExtension([
			'name' => 'ArticleNotes', 'entrypoint' => 'ArticleNotes', 'path' => $extension->getPath(), 'type' => 'system',
			'version' => $extension->getVersion(),
		]);
	}

	private static function addEntry(string $user, string $feed, string $title, string $content = 'Content'): string {
		$n = ++self::$sequence;
		$id = (string)(1734000000000000 + $n * 1000 + random_int(0, 999));
		self::assertTrue(FreshRSS_Factory::createEntryDao()->addEntry([
			'id' => $id, 'guid' => 'nb-guid-' . $id, 'title' => $title, 'author' => 'Author',
			'content' => $content, 'link' => 'http://fixtures/nb-articles/' . $id, 'date' => 1756717200 + $n, 'lastSeen' => time(),
			'hash' => md5($id), 'is_read' => false, 'is_favorite' => false, 'id_feed' => self::$feedIds[$user . $feed], 'tags' => '',
		], false));
		return $id;
	}

	/** @return array<string,mixed> */
	private static function selector(string $exact, int $start): array {
		return ['quote' => ['exact' => $exact], 'position' => ['start' => $start, 'end' => $start + strlen($exact)]];
	}

	/**
	 * Known dataset (timestamps are set explicitly): newest group first is "Wild" (T+50), then the page (T+40), the bare
	 * page highlight (T+30), "Rust async" (T+20) and "Only highlight" (T+10).
	 */
	private static function seed(string $user, bool $other = false): void {
		self::useUser($user);
		$extension = self::extension();
		$notes = $extension->noteService();
		$highlights = $extension->highlightService();
		$prefix = $other ? 'OTHER ' : '';

		$rust = self::addEntry($user, 'alpha', $prefix . 'Rust async', "<p>The ownership  model and <b>zero cost</b>\n abstractions &amp; more</p>");
		$notes->save($rust, 'Remember the borrow checker');
		$highlights->create(['entry_id' => $rust, 'source' => 'freshrss', 'selector' => self::selector('zero cost abstractions', 50),
			'color' => 'yellow', 'note' => 'great phrase']);
		$highlights->create(['entry_id' => $rust, 'source' => 'freshrss', 'selector' => self::selector('ownership model', 10), 'color' => 'green']);

		$wild = self::addEntry($user, 'beta', $prefix . 'Wild 100% juice_box');
		$notes->save($wild, 'Half price! 50% off_sale <b>bold</b>');

		$only = self::addEntry($user, 'beta', $prefix . 'Only highlight');
		$highlights->create(['entry_id' => $only, 'source' => 'freshrss', 'selector' => self::selector('lonely quote', 5),
			'color' => 'blue', 'note' => 'blue note']);

		$notes->savePageNote('https://example.org/page?utm_source=x', 'page note text', $prefix . 'Example page');
		$highlights->create(['url' => 'https://example.org/page', 'title' => $prefix . 'Example page', 'source' => 'page',
			'selector' => self::selector('page quote', 3), 'color' => 'pink']);
		$highlights->create(['url' => 'https://other.example.net/bare', 'title' => 'Bare page', 'source' => 'page',
			'selector' => self::selector('bare quote', 1), 'color' => 'purple']);

		$pdo = $notes->pdo();
		$prefixed = $pdo->prefix();
		$times = [
			['note', "entry_id={$rust}", 12], ['highlight', "entry_id={$rust}", 20],
			['note', "entry_id={$wild}", 50],
			['highlight', "entry_id={$only}", 10],
			['note', "entry_id IS NULL", 40], ['highlight', "url='https://example.org/page'", 38],
			['highlight', "url='https://other.example.net/bare'", 30],
		];
		foreach ($times as [$table, $where, $time]) {
			$pdo->exec("UPDATE {$prefixed}articlenotes_{$table} SET updated_at=" . (1000000 + $time) . " WHERE {$where}");
		}
		self::$entries[$user . 'rust'] = $rust;
		self::$entries[$user . 'wild'] = $wild;
		self::$entries[$user . 'only'] = $only;
	}

	private static function browser(string $user = self::USER): NotesBrowser {
		self::useUser($user);
		return new NotesBrowser(self::extension()->noteService()->pdo());
	}

	/**
	 * @param array<string,string> $params
	 * @return list<string>
	 */
	private static function titles(array $params, string $user = self::USER, int $page = 1, int $perPage = 25): array {
		return array_map(static fn (NotesGroup $group): string => $group->title,
			self::browser($user)->groups(NotesFilter::fromParams($params), $page, $perPage)['groups']);
	}

	public function testGroupsAreOrderedByTheirMostRecentAnnotation(): void {
		$result = self::browser()->groups(new NotesFilter());
		self::assertSame(5, $result['total']);
		self::assertSame(['Wild 100% juice_box', 'Example page', 'Bare page', 'Rust async', 'Only highlight'],
			array_map(static fn (NotesGroup $group): string => $group->title, $result['groups']));
	}

	public function testGroupContent(): void {
		$groups = self::browser()->groups(new NotesFilter())['groups'];
		$rust = $groups[3];
		self::assertSame(self::$entries[self::USER . 'rust'], $rust->entryId);
		self::assertSame('Alpha Feed', $rust->feedName);
		self::assertTrue($rust->exists);
		self::assertNotNull($rust->note);
		self::assertSame('Remember the borrow checker', $rust->note->note);
		self::assertSame(['ownership model', 'zero cost abstractions'],
			array_map(static fn (ArticleNotes\Highlight $h): string => $h->selector['quote']['exact'], $rust->highlights));
		self::assertSame(1000020, $rust->latest);
		self::assertSame(1000050, $groups[0]->latest);

		$page = $groups[1];
		self::assertTrue($page->isPage());
		self::assertSame('example.org', $page->host());
		self::assertSame('https://example.org/page?utm_source=x', $page->url);
		self::assertNotNull($page->note);
		self::assertCount(1, $page->highlights);

		$bare = $groups[2];
		self::assertNull($bare->note);
		self::assertSame('https://other.example.net/bare', $bare->url);
	}

	public function testPassagesMissingFromTheArticleAreFlaggedOrphaned(): void {
		$groups = self::browser()->groups(new NotesFilter())['groups'];
		self::assertSame([], $groups[3]->orphaned);	// both quotes are in the article text, whatever the markup and whitespace
		self::assertFalse($groups[3]->isOrphaned($groups[3]->highlights[0]));
		self::assertSame([$groups[4]->highlights[0]->uuid], $groups[4]->orphaned);	// "lonely quote" is not in "Content"
		self::assertTrue($groups[4]->isOrphaned($groups[4]->highlights[0]));
		self::assertSame([], $groups[2]->orphaned);	// passages selected on the page itself cannot be checked here
	}

	public function testAPurgedArticleIsFlagged(): void {
		self::useUser(self::USER);
		$id = self::$entries[self::USER . 'only'];
		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		self::assertNotNull($entry);
		$pdo = self::extension()->noteService()->pdo();
		$pdo->exec('DELETE FROM ' . $pdo->prefix() . 'entry WHERE id=' . (int)$id);
		try {
			$groups = self::browser()->groups(new NotesFilter())['groups'];
			self::assertFalse($groups[4]->exists);
			self::assertSame([], $groups[4]->orphaned);
			self::assertSame('Only highlight', $groups[4]->title);
			self::assertTrue($groups[3]->exists);
		} finally {
			FreshRSS_Factory::createEntryDao()->addEntry([
				'id' => $id, 'guid' => $entry->guid(), 'title' => $entry->title(), 'author' => 'Author', 'content' => 'Content',
				'link' => $entry->link(), 'date' => (int)$entry->date(true), 'lastSeen' => time(), 'hash' => md5($id),
				'is_read' => false, 'is_favorite' => false, 'id_feed' => $entry->feedId(), 'tags' => '',
			], false);
		}
	}

	public function testSearchFindsNotesQuotesAndTitles(): void {
		self::assertSame(['Rust async'], self::titles(['q' => 'borrow checker']));
		self::assertSame(['Rust async'], self::titles(['q' => 'ZERO cost']));	// quote, case-insensitive
		self::assertSame(['Rust async'], self::titles(['q' => 'great phrase']));	// highlight note
		self::assertSame(['Example page'], self::titles(['q' => 'page quote']));
		self::assertSame(['Example page', 'Bare page'], self::titles(['q' => 'page']));	// titles and notes
		self::assertSame(['Only highlight'], self::titles(['q' => 'only highlight']));	// article title
		self::assertSame([], self::titles(['q' => 'nothing like this']));
		self::assertSame([], self::titles(['q' => 'exact']));	// selector keys are not searchable
	}

	public function testSearchEscapesWildcards(): void {
		self::assertSame(['Wild 100% juice_box'], self::titles(['q' => '100%']));
		self::assertSame(['Wild 100% juice_box'], self::titles(['q' => '50% off_sale']));
		self::assertSame(['Wild 100% juice_box'], self::titles(['q' => 'juice_box']));
		self::assertSame(['Wild 100% juice_box'], self::titles(['q' => 'juice_']));
		self::assertSame([], self::titles(['q' => 'juiceXbox']));	// _ is not a wildcard
		self::assertSame([], self::titles(['q' => '1%0']));	// % is not a wildcard
		self::assertSame([], self::titles(['q' => '%%%%zzz']));
		self::assertSame([], self::titles(['q' => 'a!']));
		self::assertCount(5, self::titles(['q' => '']));
	}

	public function testKindFilter(): void {
		self::assertSame(['Wild 100% juice_box', 'Rust async'], self::titles(['kind' => 'article']));
		self::assertSame(['Example page'], self::titles(['kind' => 'page']));
		self::assertSame(['Example page', 'Bare page', 'Rust async', 'Only highlight'], self::titles(['kind' => 'highlight']));
	}

	public function testGroupsOnlyContainTheMatchingAnnotations(): void {
		$groups = self::browser()->groups(NotesFilter::fromParams(['kind' => 'highlight']))['groups'];
		self::assertNull($groups[0]->note);
		self::assertCount(2, $groups[2]->highlights);
		$groups = self::browser()->groups(NotesFilter::fromParams(['color' => 'green']))['groups'];
		self::assertCount(1, $groups);
		self::assertNull($groups[0]->note);
		self::assertSame('green', $groups[0]->highlights[0]->color);
	}

	public function testColorFilter(): void {
		self::assertSame(['Rust async'], self::titles(['color' => 'green']));
		self::assertSame(['Example page'], self::titles(['color' => 'pink']));
		self::assertSame(['Only highlight'], self::titles(['color' => 'blue']));
		self::assertSame([], self::titles(['color' => 'purple', 'q' => 'zzz']));
		self::assertSame(['Bare page'], self::titles(['color' => 'purple']));
	}

	public function testFeedFilter(): void {
		$feeds = self::browser()->feeds();
		self::assertSame(['Alpha Feed', 'Beta Feed'], array_values($feeds));
		$alpha = (string)array_search('Alpha Feed', $feeds, true);
		$beta = (string)array_search('Beta Feed', $feeds, true);
		self::assertSame(['Rust async'], self::titles(['feed' => $alpha]));
		self::assertSame(['Wild 100% juice_box', 'Only highlight'], self::titles(['feed' => $beta]));
	}

	public function testHasNoteFilter(): void {
		self::assertSame(['Wild 100% juice_box', 'Example page', 'Rust async', 'Only highlight'], self::titles(['has_note' => '1']));
		$groups = self::browser()->groups(NotesFilter::fromParams(['has_note' => '1', 'kind' => 'highlight']))['groups'];
		self::assertSame(['Rust async', 'Only highlight'], array_map(static fn (NotesGroup $group): string => $group->title, $groups));
		self::assertCount(1, $groups[0]->highlights);
	}

	public function testFiltersCombine(): void {
		self::assertSame(['Rust async'], self::titles(['kind' => 'highlight', 'color' => 'yellow', 'q' => 'zero', 'has_note' => '1']));
		self::assertSame([], self::titles(['kind' => 'page', 'color' => 'yellow']));
		self::assertSame(['Only highlight'], self::titles(['kind' => 'highlight', 'color' => 'blue', 'has_note' => '1']));
		self::assertSame([], self::titles(['kind' => 'article', 'q' => 'zero cost']));
	}

	public function testPagination(): void {
		$browser = self::browser();
		$first = $browser->groups(new NotesFilter(), 1, 2);
		self::assertSame(5, $first['total']);
		self::assertSame(['Wild 100% juice_box', 'Example page'], array_map(static fn (NotesGroup $g): string => $g->title, $first['groups']));
		self::assertSame(['Bare page', 'Rust async'], self::titles([], self::USER, 2, 2));
		self::assertSame(['Only highlight'], self::titles([], self::USER, 3, 2));
		self::assertSame([], self::titles([], self::USER, 4, 2));
		$filtered = $browser->groups(NotesFilter::fromParams(['kind' => 'highlight']), 2, 3);
		self::assertSame(4, $filtered['total']);
		self::assertSame(['Only highlight'], array_map(static fn (NotesGroup $g): string => $g->title, $filtered['groups']));
		self::assertCount(5, $browser->all(new NotesFilter()));
	}

	public function testDeletedAnnotationsAreHidden(): void {
		self::useUser(self::USER);
		$notes = self::extension()->noteService();
		$id = self::$entries[self::USER . 'wild'];
		$notes->delete($id);
		try {
			self::assertNotContains('Wild 100% juice_box', self::titles([]));
			self::assertSame(4, self::browser()->groups(new NotesFilter())['total']);
		} finally {
			$notes->save($id, 'Half price! 50% off_sale <b>bold</b>');
			$pdo = $notes->pdo();
			$pdo->exec('UPDATE ' . $pdo->prefix() . 'articlenotes_note SET updated_at=1000050 WHERE entry_id=' . (int)$id);
		}
	}

	public function testUsersNeverSeeEachOther(): void {
		self::assertSame(['OTHER Wild 100% juice_box', 'OTHER Example page', 'Bare page', 'OTHER Rust async', 'OTHER Only highlight'],
			self::titles([], self::OTHER));
		self::assertSame([], self::titles(['q' => 'OTHER'], self::USER));
		self::assertSame(['OTHER Rust async'], self::titles(['q' => 'borrow'], self::OTHER));
		self::assertCount(2, self::browser(self::OTHER)->feeds());
	}

	public function testFilterParams(): void {
		$filter = NotesFilter::fromParams(['q' => '  hi ', 'kind' => 'bogus', 'color' => 'pink', 'feed' => '12', 'has_note' => '1']);
		self::assertSame(['q' => 'hi', 'color' => 'pink', 'feed' => '12', 'has_note' => '1'], $filter->toParams());
		self::assertTrue(NotesFilter::fromParams(['color' => 'nope', 'feed' => 'x', 'has_note' => '0'])->isEmpty());
		self::assertSame(200, mb_strlen(NotesFilter::fromParams(['q' => str_repeat('é', 500)])->query));
	}
}
