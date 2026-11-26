# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- With Trusted Types enforced (`require-trusted-types-for 'script'`), the widget broke while it
  mounted and never added its field, so a protected form, the login too, always failed the
  check. Svelte writes the widget's footer to `innerHTML` as a plain string, past the widget's
  `svelte-trusted-html` policy, and `new Worker()` needs a trusted URL as well. The loader now
  creates a `default` policy that lets through the widget's texts and its two worker URLs,
  nothing else. **Allow it in the CSP:** `trusted-types svelte-trusted-html default`.
- `WidgetRenderer::ASSETS_VERSION` is `3.7.0-2`: run `bin/console extensions:configure` after the
  update, the login check stands down until the new widget files are published.

## [1.0.6] - 2026-11-23

### Development

- CI checks that every PHP file parses on each supported PHP version, runs
  `composer audit` and actionlint, and cancels the older run when a newer push
  arrives, like the other extensions.
- `SECURITY.md`, and `support` and `funding` in `composer.json`.

## [1.0.5] - 2026-11-22

### Changed

- The Composer package leaves out the tests, CI and tool configuration (`.gitattributes` with
  `export-ignore`). The ALTCHA widget in `assets/altcha/` still ships.

### Fixed

- `LICENSE` names the author instead of the extension template's placeholder.

### Development

- Rector also applies its Doctrine rules.
- ECS, PHPStan, PHPUnit and Rector use the same configuration as the other extensions.
- GitHub Actions are pinned to commit SHAs.

## [1.0.4] - 2026-11-21

Updates the vendored ALTCHA widget to 3.7.0. After updating, run `bin/console extensions:configure`
(Composer runs it unless scripts are disabled) to publish the new widget file.

### Changed

- ALTCHA widget 3.7.0 (was 3.6.0). Its only change is two new options for which form fields
  the widget sends to a `verifyUrl` (`serverVerificationFieldsSelector`,
  `serverVerificationFieldNames`), with email fields now included by default. The extension never
  sets `verifyUrl` or `serverVerificationFields` (challenges are verified on the site itself), so
  nothing changes for it. Its styles, workers and texts are unchanged. Run
  `bin/console extensions:configure` after updating to publish the new widget file
  (`WidgetRenderer::ASSETS_VERSION` is `3.7.0-1`).

### Development

- The end-to-end tests run in their own CI job, on PHP 8.2 to 8.5. `phpunit.xml.dist` has a
  `unit` and an `e2e` suite (`composer test:unit`, `composer test:e2e`); `vendor/bin/phpunit` runs
  both.

## [1.0.3] - 2026-11-21

Availability and check fixes from a second security review. After updating, run
`bin/console extensions:configure` (Composer runs it unless scripts are disabled): the project's
copy of config/services.yaml gets the new rate limiter store. A `cost` above 10,000 is now a
configuration error; `cache:clear` reports it.

### Fixed

- One client could lock every editor out of the login: the challenge endpoint answered 429 to
  everyone sharing the client's rate limit (all visitors behind a proxy Symfony doesn't trust, an
  office NAT, one IPv6 network). It now never refuses: over `challenge_rate_limit`, a client's
  challenges are four times harder (the counter range is multiplied) until it slows down. Requests
  through an untrusted proxy are logged as a warning when they go over the limit, and a rate
  limiter store that fails is logged and hands out normal challenges.
- `routes:` didn't protect routes that an authenticator answers inside the firewall
  (`form_login`, `json_login`, custom ones): the route guard ran after the firewall. It now runs
  before it (priority 10). Bolt's `bolt_login` under `routes:` is rejected; `bolt.login` protects it.
- A solution that passed one profile satisfied a check for another profile of the same request or
  a forwarded sub-request, so a cheap profile could stand in for a stricter one. A pass now counts
  for its own profile only.
- Every accepted solution left a lock file in `var/data/bolt-altcha/locks` for good (Symfony's
  `FlockStore` never deletes them). Solutions now share 256 locks. The files left by 1.0.2 can be
  deleted.
- Widget options YAML can hold but JSON can't (`.nan`, `.inf`, binary values) passed the config
  check and made the login page fail while rendering. They are rejected with the other config errors.
- The config check (`ConfigValidationWarmer`) ran when a web request rebuilt the cache, e.g. after
  Bolt's "Clear the cache", and turned that visitor's request into an error page. It is now an
  optional warmer: `cache:clear` and `cache:warmup` still run it, so deploys still fail on mistakes.
- Without the published widget files (a deploy that skipped `extensions:configure`), only the login
  check stood down: the password reset forms and `forms:` kept requiring a solution nobody could
  produce. Every automatic check now stands down, logged as critical.

### Changed

- The maximum `cost` is 10,000 (was 100,000): creating a challenge at 100,000 took ~130–175 ms
  of the server's CPU per anonymous request, not the documented ~15 ms. At 10,000 it is ~13–17 ms.
  For more work, raise the counters, which cost the server nothing. A config with a higher `cost`
  is now invalid: `cache:clear` reports it.
- `critical` and `warning` lines about a misconfiguration are logged at most once every five
  minutes, not once per request.
- `altcha_widget()` and `AltchaType` throw without a strong signing key, instead of rendering a
  widget whose challenge endpoint answers 404.
- The challenge rate limiter keeps its counts in its own store in `var/cache/…/bolt-altcha`
  (`bolt_altcha.rate_limit_pool`) and prunes expired ones.

### Added

- A dashboard notice while `config/packages/extension_bolt-altcha.yaml` is not the copy this
  version ships, i.e. `extensions:configure` didn't run after an update.

### Documentation

- Override the replay store and lock in the project's own `config/services.yaml`:
  `extensions:configure` overwrites `config/packages/extension_bolt-altcha.yaml` on every run.
- `counter_min` adds work for honest visitors only; a Trusted Types policy needs
  `trusted-types svelte-trusted-html`.

## [1.0.2] - 2026-11-21

Security and durability fixes from a review of the login and password reset protection. No
configuration changes are needed; run `bin/console extensions:configure` after updating, so the
project's copy of config/services.yaml gets the new service definitions.

### Fixed

- A replay store or lock that can't be created no longer takes the whole site down. A
  `var/data/bolt-altcha` the web server can't write to (or an unreachable Redis store) made the
  `FlockStore` throw while the container built the route guard and the form extension, so every
  request failed, even with `enabled: false`. Both stores are now passed to `ReplayStore` as
  service closures and created on first use, inside its error handling: the failure is logged as
  an error, as the documentation promised. `ReplayStore` also catches errors (`Throwable`), not
  only exceptions.
- The challenge rate limit counts IPv6 clients by `/64` network instead of by address, which a
  single host could rotate through. IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) count as their
  IPv4 address.
- `redirect_back` sends the visitor to the path and query of a same-host `Referer`, as a relative
  URL, instead of the `Referer` as given: a `Referer` such as `https://evil.example\@your.site/`
  passed the same-host check (PHP's `parse_url()` reads the host as `your.site`) but browsers
  would have followed it to `evil.example`. Real browsers never send such a `Referer`.

### Changed

- The login check also applies to every passport made by Bolt's `LoginFormAuthenticator` (also
  behind Symfony's debug `TraceableAuthenticator`), not only to POSTs to the `bolt_login` route, so
  a renamed or added Bolt login route stays covered.
- A login page served over plain HTTP is logged as a warning: browsers solve the challenge only on
  secure pages (Web Crypto), so nobody could log in.

### Added

- End-to-end tests: a Symfony kernel wired like a Bolt 6 site (the package's own
  `config/services.yaml` and routes, Bolt's real `LoginFormAuthenticator` with CSRF and login
  throttling, Bolt's real password reset form types) covering the login, the password reset forms,
  the challenge endpoint, the route guard and a replay store that can't be created.

## [1.0.1] - 2026-11-19

Maintenance release: development tools and CI are updated. The extension's code, its
configuration and its runtime requirements are unchanged.

### Changed

- Development tools updated: PHPStan 2.3.7 (was 2.3.4), phpstan-phpunit 2.1.2 (was 2.1.1),
  phpstan-strict-rules 2.1.1 (was 2.1.0) and Rector 2.8.5 (was 2.8.3).
- CI actions updated: `actions/checkout` 7.0.1, `actions/cache` 6.1.0, `actions/stale` 11.0.0
  and `shivammathur/setup-php` v2 (newer commit).
- Dependabot no longer proposes new major versions of Symfony components (Bolt 6 runs on
  Symfony 6.4) or PHPUnit.

## [1.0.0] - 2026-11-19

### Added

- ALTCHA proof-of-work protection for Bolt 6 forms, built on altcha-org/altcha 2.4 and the
  ALTCHA widget 3.6.0 (vendored, served from the site).
- Bolt's own forms by configuration: the admin login (`bolt.login`, checked before the
  credentials and counted by the login throttling) and the password reset forms
  (`bolt.reset_password`, `bolt.change_password`).
- Any Symfony form by name (`forms:`), the `AltchaType` field, hand-written forms with the
  `altcha_widget()` Twig function checked by route name (`routes:`), the `#[RequireAltcha]`
  attribute or the `Verifier` service.
- Profiles with their own challenge options and widget attributes; the profile is signed into
  every challenge.
- Signed challenges with expiry and replay protection under a lock, remembered in var/data so
  `cache:clear` doesn't make solutions reusable; the store and the lock can be pointed at Redis
  for several servers.
- The signing key from `ALTCHA_HMAC_KEY` (env or secrets vault), or derived from a strong
  `APP_SECRET`; a short or placeholder secret (Bolt's `!ChangeMe!`) switches the protection off.
- Submissions are recognised by their real and their overridden HTTP method, and a request is
  checked once (`routes:` plus `#[RequireAltcha]`, `forward()`, class plus action attribute).
- Partial form submits (PATCH, `submit($data, false)`) without the ALTCHA field fail.
- A per-IP rate limit on the challenge endpoint (`challenge_rate_limit`, default 60 a minute) and
  upper limits on the difficulty options.
- An invalid config or a weak key switches the automatic protection off (logged as critical)
  instead of breaking every form and the login; the cache warm-up fails on both.
- A dashboard notice and a login check that stands down while the published widget is missing or
  outdated (`public/assets/altcha/VERSION`).
- Every text translated in all languages of Bolt's backend (bg, cs, de, el, en, es, fr, hu, it,
  nl, pl, pt, pt_BR, ro, ru, sk, tr, uk, zh_CN): the widget's texts and the form error in the
  `bolt_altcha` domain, the login error in `security`, the flash message in `messages`. The
  widget's texts are translated by Symfony and handed to the widget, so a project can reword
  them in its own translations/bolt_altcha.<locale>.yaml.

[Unreleased]: https://github.com/Vondry/bolt-altcha/compare/v1.0.6...HEAD
[1.0.6]: https://github.com/Vondry/bolt-altcha/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/Vondry/bolt-altcha/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/Vondry/bolt-altcha/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/Vondry/bolt-altcha/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Vondry/bolt-altcha/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Vondry/bolt-altcha/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Vondry/bolt-altcha/releases/tag/v1.0.0
