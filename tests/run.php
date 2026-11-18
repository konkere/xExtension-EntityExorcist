<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$source = realpath($argv[1] ?? '');
if ($source === false || !is_file($source . '/constants.php') || is_file($source . '/constants.local.php')) {
	fwrite(STDERR, "Usage: php tests/run.php /path/to/unconfigured/FreshRSS-source\n");
	exit(2);
}

function check(mixed $expected, mixed $actual, string $case): void {
	if ($expected !== $actual) {
		throw new RuntimeException($case . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

$scratch = sys_get_temp_dir() . '/entity-exorcist-test-' . bin2hex(random_bytes(8));
if (!mkdir($scratch, 0700) || !mkdir($scratch . '/users/entitytest', 0700, true) ||
	!mkdir($scratch . '/extensions', 0700) || !mkdir($scratch . '/favicons', 0700) ||
	!symlink(dirname(__DIR__), $scratch . '/extensions/xExtension-EntityExorcist')) {
	throw new RuntimeException('Could not create the isolated test directory.');
}

function cleanTestDirectory(string $scratch): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
	}
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($files as $file) {
		if ($file->isDir() && !$file->isLink()) {
			rmdir($file->getPathname());
		} else {
			unlink($file->getPathname());
		}
	}
	rmdir($scratch);
}

$checkSavedSettings = null;
register_shutdown_function(static function () use ($scratch, &$checkSavedSettings): void {
	try {
		if ($checkSavedSettings !== null) {
			$checkSavedSettings();
		}
	} finally {
		cleanTestDirectory($scratch);
	}
});

(static function () use ($source, $scratch, &$checkSavedSettings): void {
	putenv('DATA_PATH=' . $scratch);
	define('THIRDPARTY_EXTENSIONS_PATH', $scratch . '/extensions');
	session_save_path($scratch);
	require $source . '/constants.php';
	require LIB_PATH . '/lib_rss.php';
	require_once LIB_PATH . '/Minz/Url.php';

	Minz_Session::init('FreshRSS');
	Minz_Translate::init('en');
	FreshRSS_Context::initSystem();
	check(true, file_put_contents($scratch . '/users/entitytest/config.php', '<?php return [];') !== false, 'Create test user config');
	FreshRSS_Context::initUser('entitytest', false);
	FreshRSS_Context::$isCli = true;
	check(true, FreshRSS_Factory::createUserDao()->createUser(), 'Create temporary SQLite schema');

	$categoryDao = FreshRSS_Factory::createCategoryDao();
	$categoryId = $categoryDao->addCategory(['name' => 'Test feeds']);
	check(true, is_int($categoryId) && $categoryId > 0, 'Create category');
	$feedDao = FreshRSS_Factory::createFeedDao();
	$makeFeed = static function (string $slug, string $name) use ($feedDao, $categoryId): FreshRSS_Feed {
		$feed = new FreshRSS_Feed('https://example.org/' . $slug);
		$feed->_name($name);
		$feed->_categoryId($categoryId);
		$id = $feedDao->addFeed([
			'url' => $feed->url(),
			'kind' => $feed->kind(),
			'category' => $categoryId,
			'name' => $name,
			'website' => '',
			'description' => '',
			'lastUpdate' => 0,
			'error' => 0,
		]);
		check(true, is_int($id) && $id > 0, 'Insert feed ' . $slug);
		$feed->_id($id);
		return $feed;
	};
	$selected = $makeFeed('selected', 'Sample &amp; &lt;feed&gt;');
	$excluded = $makeFeed('excluded', 'Excluded feed');

	$startExtension = static function (): EntityExorcistExtension {
		Minz_ExtensionManager::init();
		$extension = Minz_ExtensionManager::findExtension('Entity Exorcist');
		check(true, $extension instanceof EntityExorcistExtension, 'Extension discovered by metadata');
		check('EntityExorcist', $extension->getEntrypoint(), 'Entrypoint');
		check(true, $extension->install(), 'FreshRSS version support');
		Minz_ExtensionManager::enableByList(['Entity Exorcist' => true], 'user');
		check(true, $extension->isEnabled(), 'User extension is enabled');
		return $extension;
	};
	$extension = $startExtension();
	$applyHook = static fn (FreshRSS_Entry $entry): FreshRSS_Entry =>
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
	$makeEntry = static fn (FreshRSS_Feed $feed, string $title): FreshRSS_Entry =>
		new FreshRSS_Entry($feed->id(), 'manual-guid', $title);
	$encoded = '&amp;quot;Example&amp;quot;';
	check($encoded, $applyHook($makeEntry($selected, $encoded))->title(), 'Empty allowlist');

	$setConfig = static function (array $ids, bool $normalize = false) use ($startExtension): EntityExorcistExtension {
		FreshRSS_Context::userConf()->extensions = [
			'Entity Exorcist' => ['title_repair' => ['feed_ids' => $ids, 'normalize_nbsp' => $normalize]],
		];
		return $startExtension();
	};
	$extension = $setConfig([$selected->id()]);
	$extension->handleConfigureAction();
	check(true, isset($extension->feeds[$selected->id()], $extension->feeds[$excluded->id()]), 'List real feed IDs');
	$form = $extension->getConfigureView();
	check(true, str_contains($form, 'name="_csrf"'), 'Native CSRF field');
	check(true, str_contains($form, 'value="' . $selected->id() . '" checked'), 'Selected checkbox');
	check(false, str_contains($form, 'value="' . $excluded->id() . '" checked'), 'Excluded checkbox');
	check(true, str_contains($form, 'Sample &amp; &lt;feed&gt;'), 'Feed label is escaped');

	check($encoded, $applyHook($makeEntry($excluded, $encoded))->title(), 'Excluded feed');
	foreach ([Minz_HookType::EntryBeforeInsert, Minz_HookType::EntryBeforeUpdate] as $hook) {
		$entry = Minz_ExtensionManager::callHook($hook, $makeEntry($selected, $encoded));
		check($encoded, $entry->title(), $hook->value . ' does not repair titles');
	}
	$updated = $makeEntry($selected, $encoded);
	$updated->_isUpdated(true);
	check($encoded, $applyHook($updated)->title(), 'Skip entries marked as updated');
	$untitled = new FreshRSS_Entry($selected->id(), 'missing-&amp;quot;');
	check($untitled->guid(), $applyHook($untitled)->title(), 'Skip GUID fallback');

	$cases = [
		'decimal' => ['Dash &amp;#45;', 'Dash -'],
		'hex' => ['Emoji &amp;#x1F600; &amp;#X2014;', 'Emoji 😀 —'],
		'ampersand' => ['Rock &amp;amp; Roll', 'Rock &amp; Roll'],
		'quotes' => ['&amp;apos;A&amp;#39; &amp;#34;B&amp;quot;', '&#039;A&#039; &quot;B&quot;'],
		'HTML5 multi-codepoint' => ['&amp;NotEqualTilde;', "≂\u{0338}"],
		'NBSP' => ['A&amp;nbsp;B&amp;#160;C&amp;#xA0;D', "A\u{00A0}B\u{00A0}C\u{00A0}D"],
		'one pass' => ['&amp;amp;quot; &amp;amp;#45;', '&amp;quot; &amp;#45;'],
		'plain ampersand and Unicode' => ['R&D — Артём', 'R&D — Артём'],
		'already escaped' => ['&quot;Rock &amp; Roll&quot; &lt;3', '&quot;Rock &amp; Roll&quot; &lt;3'],
		'unknown or incomplete' => ['&amp;fictional; &amp;quot &amp;#x110000;', '&amp;fictional; &amp;quot &amp;#x110000;'],
		'control character' => ['Line&amp;NewLine;break', 'Line&amp;NewLine;break'],
		'HTML stays text' => ['&amp;lt;b&amp;gt;Text&amp;lt;/b&amp;gt;', '&lt;b&gt;Text&lt;/b&gt;'],
	];
	foreach ($cases as $case => [$input, $expected]) {
		check($expected, $applyHook($makeEntry($selected, $input))->title(), $case);
	}

	$parser = new FreshRSS_SimplePieCustom();
	$parser->enable_cache(false);
	$parser->set_raw_data(file_get_contents(__DIR__ . '/fixture.xml'));
	check(true, $parser->init(), 'Parse RSS with FreshRSS SimplePie');
	$expectedTitles = [
		'entity-exorcist-quotes-1' => '"Сантафобия"',
		'entity-exorcist-dash-1' => 'Black Lake - Official Story Trailer',
		'entity-exorcist-normal-1' => '"Normal" & safe <text>',
	];
	$entryDao = FreshRSS_Factory::createEntryDao();
	$count = 0;
	foreach ($selected->loadEntries($parser) as $entry) {
		$count++;
		$entry->_id(uTimeString());
		$before = $entry->toArray();
		$expected = $expectedTitles[$entry->guid()];
		if ($entry->guid() !== 'entity-exorcist-normal-1') {
			check(false, $expected === htmlspecialchars_decode($before['title'], ENT_QUOTES), 'Broken title reaches FreshRSS hook');
		}
		check($entry, $applyHook($entry), 'Hook returns the same entry');
		$after = $entry->toArray();
		$before['title'] = $after['title'];
		check($before, $after, 'Only title changes, including preserving source hash');
		check($expected, htmlspecialchars_decode($after['title'], ENT_QUOTES), 'HTML text from stored title');
		check(true, $entryDao->addEntry($after), 'Stage entry through FreshRSS DAO');
		check(true, $entryDao->commitNewEntries(), 'Commit newly added entry');
		$stored = $entryDao->searchByGuid($selected->id(), $entry->guid());
		check($after['title'], $stored?->title(), 'SQLite round trip');
		$stored->_feed($selected);
		$apiTitle = str_replace(['&', '<', '>'], ['＆', '＜', '＞'], $expected);
		check($apiTitle, $stored->toGReader('compat')['title'], 'Google Reader API title');
	}
	check(count($expectedTitles), $count, 'All fixture articles tested');

	$setConfig([$selected->id()], true);
	check('A B C', $applyHook($makeEntry($selected, "A&amp;nbsp;B\u{00A0}C"))->title(), 'NBSP normalization enabled');
	check('A&amp;nbsp;B', $applyHook($makeEntry($excluded, 'A&amp;nbsp;B'))->title(), 'NBSP does not affect unselected feeds');
	check('&amp;nbsp;', $applyHook($makeEntry($selected, '&amp;nbsp;'))->title(), 'Do not create an empty title');

	check(1, $feedDao->deleteFeed($selected->id()), 'Delete selected feed in temporary DB');
	$replacement = $makeFeed('selected', 'Re-added feed');
	check(false, $replacement->id() === $selected->id(), 'Re-added feed has a new ID');
	check($encoded, $applyHook($makeEntry($replacement, $encoded))->title(), 'Re-added feed starts unselected');

	$checkSavedSettings = static function () use ($scratch, $replacement): void {
		$saved = require $scratch . '/users/entitytest/config.php';
		$expected = ['feed_ids' => [$replacement->id()], 'normalize_nbsp' => true];
		check($expected, $saved['extensions']['Entity Exorcist']['title_repair'], 'Save valid feed IDs and NBSP in user configuration');
		echo 'FreshRSS ' . FRESHRSS_VERSION . ": hook, configuration form and save, RSS parser, SQLite and Google Reader serialization passed.\n";
	};
	$_SERVER['REQUEST_METHOD'] = 'POST';
	Minz_Request::_params([
		'entity_exorcist_feeds' => [(string)$replacement->id(), (string)$selected->id(), 'bogus', '98765'],
		'entity_exorcist_nbsp' => '1',
	]);
	$startExtension()->handleConfigureAction();
	throw new RuntimeException('Expected FreshRSS to redirect after saving.');
})();
