# Entity Exorcist

[Читать README на русском](README.ru.md)

> Your feeds are haunted. We brought a decoder.

Exorcises malformed HTML entities from RSS article titles.

Entity Exorcist repairs new article titles in the feeds you select. FreshRSS saves the repaired titles in its database and uses them in the web interface and API.

## Installation

Use FreshRSS **1.29.0 or later**, PHP **8.1 or later**, and Git. Tested with FreshRSS 1.29.0 and 1.30.4.

Clone the repository from inside your FreshRSS installation's `extensions/` directory.

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/konkere/xExtension-EntityExorcist.git
```

If your installation sets `THIRDPARTY_EXTENSIONS_PATH`, run `git clone` in that directory. For Docker with a bind mount for extensions, clone into the corresponding directory on the host. FreshRSS should find `metadata.json` directly inside the new `xExtension-EntityExorcist/` directory. [FreshRSS extension installation guide](https://freshrss.github.io/FreshRSS/en/admins/15_extensions.html#how-to-install)

To update a Git installation, run `git -C xExtension-EntityExorcist pull --ff-only` from the same extensions directory.

## Configuration

1. Open **Configuration → Extensions**, enable **Entity Exorcist**, and open its settings.
2. Under **Feeds to repair**, expand your FreshRSS categories, select the subscriptions you want to process, and save. Each category shows its number of feeds. Categories with previously selected feeds open automatically. Each FreshRSS user has their own selection, which starts empty. Clear all checkboxes and save to stop processing.

The settings list each subscription by name and ID. If you delete and resubscribe to a feed, select it again because it gets a new ID. Saving discards IDs of deleted feeds. Review the selection after restoring a database with different feed IDs.

**Replace non-breaking spaces with regular spaces in selected feeds** is off by default. With the option off, `&nbsp;`, `&#160;`, and `&#xA0;` decode to a non-breaking space (U+00A0). Turn it on to convert all U+00A0 characters in new titles from selected feeds to ordinary spaces. FreshRSS trims ordinary spaces at the start and end of titles when saving.

## How title repair works

The extension unwraps FreshRSS's text escaping, runs PHP's `html_entity_decode` with `ENT_HTML5` once, and escapes the result again before storage. Unknown or incomplete references such as `&unknown;` and `&quot` stay literal. The extension keeps the original title if the decoded title is empty, contains control characters, or equals the GUID. In a selected feed, a valid entity that the publisher intended as literal text, such as `&copy;`, will also be decoded.

| Text after XML parsing | Text after repair |
| --- | --- |
| `&quot;Сантафобия&quot;` | `"Сантафобия"` |
| `Black Lake &#45; Official Story Trailer` | `Black Lake - Official Story Trailer` |
| `Rock &amp; Roll` | `Rock & Roll` |
| `&#x1F600; &NotEqualTilde;` | `😀 ≂̸` |
| `&amp;quot;Quote&amp;quot;` | `&quot;Quote&quot;` |
| `R&D — an ordinary title` | `R&D — an ordinary title` |

XML parsing already turns `<title>&quot;Quote&quot;</title>` into a title with quotation marks. In `<title><![CDATA[&quot;Quote&quot;]]></title>`, the references survive XML parsing as literal text and the extension repairs them. One layer remains when a title was encoded twice. Decoded angle brackets display as text.

The extension runs on `EntryBeforeAdd` before FreshRSS stores new entries, including new entries from imports. FreshRSS also invokes `EntryBeforeInsert` on updates, so the extension uses the add-only hook. Existing articles are left alone. For new entries, the article body, link, GUID, and source hash are preserved. During a feed refresh, FreshRSS applies its filters before the extension repairs the title. [FreshRSS hook documentation](https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html) · [Feed refresh code in 1.30.4](https://github.com/FreshRSS/FreshRSS/blob/1.30.4/app/Controllers/feedController.php)

## Test on a FreshRSS instance

### Use the live feed without waiting for new posts

On FreshRSS 1.30.4, import [horrorzone-live.opml](tests/horrorzone-live.opml) via **Subscription management → Import / export**. The file uses the original URL, `https://horrorzone.ru/feed`. If your account already follows HorrorZone, use a separate account on the test instance. Importing the same URL reuses the existing subscription.

The new **HorrorZone — Entity Exorcist test** subscription has automatic refresh disabled. Select it in the Entity Exorcist settings, then open that subscription's settings and click **Refresh feed**. FreshRSS imports the articles currently available from HorrorZone. You can delete the test subscription when you're done.

**Reload articles** fetches the source again, but FreshRSS still recognizes existing articles by GUID. To test repair on insert, import articles into an empty subscription. [Reload implementation in FreshRSS 1.30.4](https://github.com/FreshRSS/FreshRSS/blob/1.30.4/app/Controllers/feedController.php#L1230)

### Use an existing subscription or the RSS fixture

1. Subscribe to [HorrorZone](https://horrorzone.ru/feed) and select it in the extension settings. FreshRSS may finish the initial refresh before you can select the feed.
2. When a new article with literal entities appears, refresh the feed. Compare the title in the web interface and in your Android client after syncing. Verify that previously stored articles and articles in an unselected feed stay the same.
3. For a repeatable test, serve the [RSS fixture](tests/fixture.xml) at a URL your test instance can already reach. Start with an empty channel. Subscribe and enable repair for that feed, then add the fixture's `item` elements at the same URL and refresh. To repeat the test, give the fixture items new GUIDs. Use the table above for the expected titles.

FreshRSS's Google Reader compatibility mode and Fever can return `＆`, `＜`, and `＞` in place of their ASCII equivalents. This is standard FreshRSS formatting for those APIs. The quotation marks and hyphen in the HorrorZone examples are returned as shown in the table. [Google Reader serialization in FreshRSS](https://github.com/FreshRSS/FreshRSS/blob/1.30.4/app/Models/Entry.php)

## Run local checks without a server

Run these commands from the extension directory with a clean, unpacked copy of the FreshRSS source. Use PHP CLI with the FreshRSS modules, including DOM/XML, cURL, mbstring, and PDO SQLite.

```sh
php -l extension.php
php -l configure.phtml
php -l tests/run.php
php tests/run.php /path/to/FreshRSS-1.30.4
```

The test exercises FreshRSS's extension loader, settings form and persistence, SimplePie parsing, temporary SQLite storage, and Google Reader serialization. It removes the temporary test data on exit and requires no HTTP server.

## License

[MIT](LICENSE)
