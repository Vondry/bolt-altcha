# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Vondry/bolt-altcha/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/Vondry/bolt-altcha/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Vondry/bolt-altcha/releases/tag/v1.0.0
