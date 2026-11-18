<?php
declare(strict_types=1);

final class EntityExorcistExtension extends Minz_Extension {
	private const CONFIG_KEY = 'title_repair';
	private const MIN_VERSION = '1.29.0';
	private const TEXT_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401;

	/** @var array<int,FreshRSS_Category> */
	public array $categories = [];

	public static function isSupported(): bool {
		return version_compare(FRESHRSS_VERSION, self::MIN_VERSION, '>=');
	}

	public function install(): bool|string {
		return self::isSupported() ? true : $this->compatibilityMessage();
	}

	public function compatibilityMessage(): string {
		return 'Entity Exorcist requires FreshRSS ' . self::MIN_VERSION . ' or newer.';
	}

	public function init(): void {
		parent::init();
		if (self::isSupported()) {
			$this->registerHook(Minz_HookType::EntryBeforeAdd, $this->repairTitle(...));
		}
	}

	/** @return array{feed_ids: list<int>, normalize_nbsp: bool} */
	public function settings(): array {
		$config = $this->getUserConfigurationArray(self::CONFIG_KEY) ?? [];
		$feedIds = is_array($config['feed_ids'] ?? null) ? $config['feed_ids'] : [];

		return [
			'feed_ids' => array_values(array_filter($feedIds, static fn ($id): bool => is_int($id) && $id > 0)),
			'normalize_nbsp' => ($config['normalize_nbsp'] ?? false) === true,
		];
	}

	public function handleConfigureAction(): void {
		if (!self::isSupported()) {
			return;
		}

		if (!Minz_Request::isPost()) {
			$this->categories = array_filter(
				FreshRSS_Factory::createCategoryDao()->listSortedCategories(),
				static fn (FreshRSS_Category $category): bool => $category->feeds() !== [],
			);
			return;
		}

		$feeds = FreshRSS_Factory::createFeedDao()->listFeeds();
		$selected = array_fill_keys(Minz_Request::paramArrayString('entity_exorcist_feeds'), true);
		$this->setUserConfigurationValue(self::CONFIG_KEY, [
			'feed_ids' => array_keys(array_intersect_key($feeds, $selected)),
			'normalize_nbsp' => Minz_Request::paramString('entity_exorcist_nbsp') === '1',
		]);
		Minz_Request::good(_t('feedback.conf.updated'), [
			'c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()],
		]);
	}

	private function repairTitle(FreshRSS_Entry $entry): FreshRSS_Entry {
		$settings = $this->settings();
		if ($entry->isUpdated() || !in_array($entry->feedId(), $settings['feed_ids'], true)) {
			return $entry;
		}

		$storedTitle = $entry->title();
		if ($storedTitle === $entry->guid()) {
			return $entry;
		}

		$plainTitle = htmlspecialchars_decode($storedTitle, self::TEXT_FLAGS);
		$decoded = html_entity_decode($plainTitle, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		if ($settings['normalize_nbsp']) {
			$decoded = str_replace("\u{00A0}", ' ', $decoded);
		}
		if ($decoded === $plainTitle || trim($decoded) === '' ||
			preg_match('/[\x00-\x1F\x7F-\x{009F}]/u', $decoded) !== 0) {
			return $entry;
		}

		$safeTitle = htmlspecialchars($decoded, self::TEXT_FLAGS, 'UTF-8');
		if (trim($safeTitle) === $entry->guid()) {
			return $entry;
		}

		$sourceHash = $entry->hash();
		$entry->_title($safeTitle);
		$entry->_hash($sourceHash);

		return $entry;
	}
}
