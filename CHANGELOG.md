# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Vondry/bolt-altcha/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Vondry/bolt-altcha/releases/tag/v1.0.0
