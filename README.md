# Translation Project extension for MediaWiki

This extension is used to monitor the progression of the Kol-Zchut
Hebrew->Arabic translation project.

## Configuration
- $wgTranslationManagerAutoSaveWordCount (boolean): save word count into the database directly from the word counter special page.
- $wgTranslationManagerAutoSetEndTranslationOnWordCount (boolean): set end date to today on word count.
- $wgTranslationManagerValidLanguages (array): an array of allowed language codes (e.g. ['en', 'ar'] )
  These determine the target languages you can manage.

### User Preferences
The extension adds the user preference "translationmanager-language", which is set to the user's preferred
language code (e.g. 'ar') for translation work. This is also used by extension:ExportForTranslation.

### Login details for target wiki
These are required for creating redirects on the target wiki:
- $wgTranslationManagerTargetWikiApiURL: the full url to the api (e.g., 'https://localhost/wiki/api.php').
  You may use a placeholder $1 to be repalced automatically by a language code, if you use a wiki family.
- $wgTranslationManagerTargetWikiUserName
- $wgTranslationManagerTargetWikiUserPassword

The credentials should be a [bot password](https://www.mediawiki.org/wiki/Manual:Bot_passwords) granting
the `edit` and `move` rights (and `createpage` if your wiki restricts page creation).

### Optional: target wiki ID (for wiki farms)
- $wgTranslationManagerTargetWikiId (string|null, default null): the wiki-farm domain ID of the target
  wiki (e.g. `'kz_ar'`). The placeholder `$1` is replaced with the language code, so a single value like
  `'kz_$1'` covers a multi-language farm.

  When set **and** the resolved ID is in `$wgLocalDatabases`, a `fixDoubleRedirect` job is pushed onto
  the target wiki's queue after every successful move. This compensates for a long-standing gap in
  MediaWiki core where `action=move` (unlike `Special:MovePage`) does not queue double-redirect
  cleanup. Without it, after moving a redirect you'd be left with `Old → New → :he:Origin` until
  someone runs `maintenance/fixDoubleRedirects.php` by hand.

  Leave null if the target wiki isn't part of the same MediaWiki install — we have no way to reach
  its job queue otherwise.

## Redirect creation: design notes
Even when the target wiki shares a server and database with the source wiki, the redirect is still created
by calling its `api.php` over HTTP (with bot-password authentication, using MediaWiki's own
`HttpRequestFactory` — no external API-client library). The reasons:

- MediaWiki 1.43 has no in-process API for editing or moving pages in a different wiki of the same farm.
  Services like `WikiPageFactory` and `MovePageFactory` are bound to the current wiki's `$wgDBname` and
  cannot be re-targeted mid-request.
- Going over HTTP keeps the call inside the supported MediaWiki contract (permissions, hooks, parser cache
  invalidation, recentchanges) without us having to reimplement any of it.

Two more efficient alternatives were considered and may be worth revisiting if requirements change:

1. **Push a job to the target wiki's queue** via `JobQueueGroupFactory::makeJobQueueGroup( $domain )`.
   Async, fully in-process, no HTTP. The trade-off is that the UI cannot immediately report
   created/exists/moved — it must show "queued" and reconcile later.
2. **Shell out to `maintenance/edit.php` with `--wiki=<lang>`** and a tiny custom `MoveSingle.php`
   maintenance script for the move case. Synchronous, no HTTP, but pays ~50–100ms per invocation for
   PHP process spawn and parses English error strings out of stderr.

## Dependencies
### Hard dependencies
- Extension:AdditionalFormInputs, which adds a positive-integer HTML field

### Soft dependencies
- Extension:ExportForTranslation (used for word count and export actions) >= 1.0.0
- Extension:ArticleType (extra filtering enabled if available)
- Extension:ArticleContentArea (extra filtering)

## Usage
### Special pages
- Special:TranslationManager: overview for managing translations
- Special:TranslationManagerWordCounter: count words in a translation against the original export;
  requirs the installation of ExportForTranslation extension.
- Special:TranslationManagerPersonnel: manage the translation project's personnel (translators and editors)
- Special:TranslationManagerStatusEditor: manage the status of a single translation. Accessed through the overview page.

## Tests
### Unit tests
Pure unit tests for `RemoteWikiApi` (no DB, no HTTP) mock `HttpRequestFactory` and assert both the
branching of `updateRedirect()` and the exact requests sent to `api.php`.
```
docker exec kz-main-mediawiki sh -c \
  'cd /var/www/html && vendor/bin/phpunit --testsuite=extensions:unit --filter RemoteWikiApi'
```

### Integration tests
End-to-end tests against a real target wiki. **Opt-in** via environment variables — skipped otherwise
so they cannot fire in CI or against production. They create pages with a `TMIT_<timestamp>_*` prefix
and clean them up in `tearDown` via `maintenance/deleteBatch.php --wiki=<target>`.

**One-time wiki configuration**: `settings/CommonSettings.php` must expose the kz-specific
`edit-main` right via the standard bot-password grants — without this, bot sessions are
rejected with `protectednamespace` on every NS_MAIN edit:
```php
$wgGrantPermissions['editpage']['edit-main']           = true;
$wgGrantPermissions['createeditmovepage']['edit-main'] = true;
```

**Provisioning the test bot** (idempotent — re-run anytime to rotate the secret):
```
docker exec kz-main-mediawiki php \
  /var/www/html/extensions/WikiRights/TranslationManager/maintenance/setupIntegrationTestBot.php \
  --wiki=ar --user=TMIntBotAR --force-new-password
```
The script creates the user (working around the kz shared-actor / per-wiki-user collision),
adds it to `editor`/`sysop`/`bot` groups, mints a bot password with the right grants, and
prints the env-var block for the test runner. See the script header for what it does.

**Env vars** that need to be set before invoking PHPUnit (copy the three the script prints,
plus the two below for URL and language):

```
TRANSLATIONMANAGER_TEST_API_URL='http://kz-main-mediawiki/$1/api.php'
TRANSLATIONMANAGER_TEST_USER='TestSysop@TMIntegration'
TRANSLATIONMANAGER_TEST_PASSWORD='<bot password secret>'
TRANSLATIONMANAGER_TEST_TARGET_LANG='ar'
TRANSLATIONMANAGER_TEST_TARGET_WIKI='kz_ar'
```

Run:
```
docker exec \
  -e TRANSLATIONMANAGER_TEST_API_URL -e TRANSLATIONMANAGER_TEST_USER \
  -e TRANSLATIONMANAGER_TEST_PASSWORD -e TRANSLATIONMANAGER_TEST_TARGET_LANG \
  -e TRANSLATIONMANAGER_TEST_TARGET_WIKI \
  kz-main-mediawiki sh -c \
  'cd /var/www/html && vendor/bin/phpunit \
    extensions/WikiRights/TranslationManager/tests/phpunit/integration/RemoteWikiApiIntegrationTest.php'
```

## Changelog
### 1.1.0, 2026-05-18
- Drop `addwiki/mediawiki-api` dependency; call the target wiki's `api.php` directly through
  MediaWiki's `HttpRequestFactory`. Same HTTP-based behaviour, one fewer third-party library.
- Manage HTTP cookies internally instead of relying on `MWHttpRequest::setCookieJar`, which
  silently drops cookies whose `Set-Cookie` omits a `Domain=` attribute and triggers a PHP 8.3
  `strlen(null)` deprecation. Without this, the login → CSRF → edit chain never completes.
- New optional `$wgTranslationManagerTargetWikiId` config. When the target wiki is part of the same
  MediaWiki install, a `fixDoubleRedirect` job is queued on its job queue after every move, so
  the leave-behind redirect is automatically flattened (works around `action=move` not queuing
  this job, unlike `Special:MovePage`).
- Add unit tests and an opt-in integration test for `RemoteWikiApi`.
### 1.0.0, 2026-02-24
- MediaWiki 1.43 compatibility: replace deprecated wfGetDB() with ConnectionProvider
- Use ExportForTranslation's service instead of ExtensionRegistry::isLoaded() checks; requires ExportForTranslation >= 1.0.0
### 0.9.0, 2025-03-27
- Add a new special page to manage the translation project's personnel (translators and editors)
- Refactor the code (quite) a bit
### 0.8.0, 2023-01-23
- Multi-lingual support, including a user preference for default language
### 0.7.0, 2021-09-17
- Make extension ArticleType optional; use its new getJoin() function to filter without knowing table specifics.
### 0.6.0, 2021-08-03
- Drop the main_category field and use Extension:ArticleContentArea instead
### 0.5.1, 2021-06-08
- The word counter will now try to compare with the actual exported revision, instead of the current revision.
### 0.5.0, 2021-02-24
- Allow to programatically get suggestions by article IDs (with or without language links), not just
  all rows
### 0.4.1, 2019-08-13
- Do not create redirects if the article is already translated
### 0.4.0, 2017-08-03
- Use an API client to create redirects on a remote wiki whenever a new translation suggestion is added
