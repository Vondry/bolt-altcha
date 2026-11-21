# ALTCHA for Bolt CMS

Protects the forms of a Bolt 6 site from bots with [ALTCHA](https://altcha.org), a self-hosted, open-source (MIT)
proof-of-work challenge. Before a form is sent, the visitor's browser solves a small puzzle in the background: no
pictures to click, no third-party request, no cookie. By default nothing is shown at all.

- **Bolt's own forms by configuration only**: the admin login and the forgotten-password forms, with one line of YAML
  each and no template changes. A failed check on the login is the usual login error and counts towards Bolt's login
  throttling.
- **Any Symfony form** by its name in the config, or with the `AltchaType` field in your own form.
- **Hand-written forms** with a Twig function, verified by route name in the config, a `#[RequireAltcha]` attribute or
  the `Verifier` service.
- **The library's own options**: challenge options map to [altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php)
  `CreateChallengeOptions`; widget attributes are passed to `<altcha-widget>` verbatim.
- **Profiles**: named sets of difficulty and widget options. The profile is signed into every challenge, so a solution
  for the newsletter is useless on the login.
- **Secure by default**: signed challenges with an expiry, one use per solution (replay protection under a lock), a
  generic error for every failure, a rate limit on the challenge endpoint, and no way around the check by HTTP method
  override.
- **Hard to break a site with**: a typo in the config or a missing deploy step switches the protection off and logs it,
  instead of turning every form and the admin login into an error page; the cache warm-up (`cache:clear`) fails on the
  same mistakes, so a deploy catches them.
- **Strict CSP friendly**: the widget, its workers and its translations are served from your site. Tested under
  `Content-Security-Policy: default-src 'self'`.

## Requirements

- Bolt CMS `^6.0`, PHP `>=8.2`
- Visitors' browsers with Web Crypto (every current browser). Without JavaScript a protected form can't be sent.
- **HTTPS.** Browsers offer Web Crypto only on secure pages (HTTPS, or `localhost`): served over plain HTTP, the widget
  can't solve its challenge and a protected form (the login too) can't be sent. A login page served over HTTP is
  logged as a warning. Behind a proxy that terminates TLS, configure Symfony's trusted proxies.

## Installation

```bash
composer require tomvondracek/bolt-altcha
bin/console extensions:configure --with-config
bin/console cache:clear
```

`extensions:configure` publishes the widget to `public/assets/altcha/`, and copies the route, the `@bolt-altcha` Twig
namespace, the form theme and the translations into the project (`config/routes/extension_bolt-altcha.yaml`,
`config/packages/extension_bolt-altcha.yaml`). `--with-config` copies the configuration to
`config/extensions/tomvondracek-altcha.yaml`. Run `extensions:configure` again after every update of the extension:
it refreshes the published widget.

Set the signing key in `.env.local`, or in Symfony's secrets vault (`bin/console secrets:set ALTCHA_HMAC_KEY`), at
least 32 random characters:

```bash
echo "ALTCHA_HMAC_KEY=$(openssl rand -hex 32)" >> .env.local
```

Without it, the key is derived from `APP_SECRET`, which must then be strong as well: not Bolt's placeholder
`!ChangeMe!` and at least 32 characters. Anyone who knows the key can sign challenges with the answer already in them,
so a weak or placeholder secret switches the protection off (logged as critical), and `cache:clear` fails as soon as the
config protects something. Neither secret is ever used directly: two keys are derived from it with separate labels.

Nothing is protected until you switch it on (next section).

## Bolt's own forms

```yaml
# config/extensions/tomvondracek-altcha.yaml
bolt:
  login: true            # /bolt/login
  reset_password: true   # /bolt/reset-password, the "send me a link" form
  change_password: false # /bolt/reset-password/reset, already needs a valid link from the e-mail
```

Each takes `false`, `true` (the profile of the same name, e.g. `login`, or `default`) or a profile name.

The login check applies to every passport made by Bolt's `LoginFormAuthenticator` (whatever the route) and to every
POST to `bolt_login`.

| Form | Where the widget goes | How it's checked | On failure |
|---|---|---|---|
| Login | the `login_middle` widget slot, which Bolt's template prints (and must a theme's `login_template`) | a listener on the passport check, after the login throttling and before the user lookup and the password check: a bot costs no database query and no password hashing | Bolt's login error, here "The check that you are not a robot failed. Please try to log in again." (`security` domain); it counts towards `login_throttling` |
| Password reset forms | a field added to the form, rendered by `form_end()` | on submit | a form error; nothing about whether the account exists |

**Themes with `form_theme … only`**: a template that lists its form themes with `only` must include the extension's:

```twig
{% form_theme requestForm with ['form_div_layout.html.twig', '@bolt-altcha/form/altcha.html.twig'] only %}
```

## Symfony forms

Without code, by the name of the root form (the prefix of its fields, `contact` for `contact[email]`):

```yaml
forms:
  contact: true        # profile `default`
  order: strict        # profile `strict`
```

Or in your own form type, with any widget attributes:

```php
use Tomvondracek\Altcha\Form\AltchaType;

$builder->add('altcha', AltchaType::class, [
    'profile' => 'contact',                                  // default: 'default'
    'widget' => ['display' => 'standard', 'auto' => 'onload'], // <altcha-widget> attributes
]);
```

The field is not mapped to the form data. It is checked on submit (not as a validation constraint, so whatever
`validation_groups` the form has) and puts a translated error on itself.

## Hand-written forms

Put the widget into the form:

```twig
<form method="post" action="{{ path('newsletter_subscribe') }}">
    <input type="email" name="email" required>
    {{ altcha_widget('newsletter') }}
    <button>Subscribe</button>
</form>
```

Then have it checked in one of three ways:

1. **By route name**, before the controller runs:
   ```yaml
   routes:
     newsletter_subscribe: newsletter
   ```
2. **With an attribute** on your controller (class or method):
   ```php
   use Tomvondracek\Altcha\Attribute\RequireAltcha;

   #[Route('/newsletter', name: 'newsletter_subscribe', methods: ['POST'])]
   #[RequireAltcha(profile: 'newsletter')]
   public function subscribe(Request $request): Response
   ```
3. **With the service**, for full control:
   ```php
   use Tomvondracek\Altcha\Verification\Verifier;

   $result = $verifier->verifyRequest($request, 'newsletter');
   if (! $result->isValid()) {
       // $result->status: Missing, Malformed, Expired, Invalid, WrongProfile, Replayed
   }
   ```

Routes and the attribute answer a failure as `on_route_failure` says: `redirect_back` (default) sends the visitor back
to the form (the path and query of the `Referer`, only on the same host, else the homepage) with a flash message of type `error`, translated
in the `messages` domain; `reject` answers `403` with an empty body. Only unsafe methods (POST, PUT, PATCH, DELETE) are checked.

A form that posts straight to another service (a Mailchimp or Ecomail embed) can't be protected: its data never
reaches Bolt. Post it to a route of your own, check it there and forward it.

### Twig functions

| Function | Output |
|---|---|
| `altcha_widget(profile = 'default', attributes = {}, name = null)` | `<altcha-widget>` with the profile's attributes, merged with `attributes`; the stylesheet and script before it, once per page. `name`: the hidden field (default `field_name`). |
| `altcha_script()` | only the stylesheet and script, e.g. for the `<head>` |
| `altcha_challenge_url(profile = 'default')` | the challenge URL, for your own widget setup |

## Configuration

`config/extensions/tomvondracek-altcha.yaml` (a `tomvondracek-altcha_local.yaml` next to it overrides single keys,
e.g. `enabled: false` on a development machine). Every key is optional; the copied file documents them all.

```yaml
enabled: true                 # false: no widget, every check passes
field_name: altcha            # the hidden field with the solution
challenge_rate_limit: 60      # challenges per minute and IP address; 0 = no limit

challenge:                    # defaults of every profile, see "Difficulty"
  algorithm: PBKDF2/SHA-256   # PBKDF2/SHA-256|384|512, SHA-256|384|512
  cost: 1000                  # 1–100000
  counter_min: 1000           # 1–1000000
  counter_max: 5000
  key_length: 32              # even, 16–64
  expires: 600                # seconds, 30–86400

widget:                       # <altcha-widget> attributes, verbatim
  auto: onsubmit              # off | onfocus | onload | onsubmit
  display: invisible          # standard | bar | floating | overlay | invisible
  configuration:              # the widget's JSON options, camelCase
    hideLogo: true
    hideFooter: true

profiles:                     # each overrides `challenge` and `widget` key by key
  default: {}
  login:
    challenge: { counter_min: 2000, counter_max: 8000 }
  newsletter:
    widget: { display: floating }

bolt: { login: false, reset_password: false, change_password: false }
forms: {}
routes: {}
on_route_failure: redirect_back
```

Widget attributes are those of the [ALTCHA widget](https://github.com/altcha-org/altcha#configuration): `auto`,
`display`, `type`, `theme`, `workers`, `form`, `language` and the JSON `configuration`. A `null` value removes an
attribute set on a broader level. `challenge` and `name` are set by the extension; `language` follows the page's
locale.

## Translations

Every text is translated in the site's language, in all the languages of Bolt's backend (bg, cs, de, el, en, es, fr,
hu, it, nl, pl, pt, pt_BR, ro, ru, sk, tr, uk, zh_CN), the way Bolt's other extensions do it: catalogs in
`translations/` with the English text as the message id, loaded by `framework.translator.paths` (copied by
`extensions:configure`).

| Domain | Texts |
|---|---|
| `bolt_altcha` | the widget's texts ("I’m not a robot", "Verifying...", its errors) and the form field error |
| `security` | the login error, which Bolt's login template translates in this domain |
| `messages` | the flash message of a failed check on a protected route |

To reword a text, put it into the project's catalog of that domain, e.g. `translations/bolt_altcha.cs.yaml`:

```yaml
'I’m not a robot': 'Jsem člověk'
```

The widget's texts are translated by Symfony, not by the widget's own language files: the page prints them as an inert
`<script type="application/json">` and the loader hands them to the widget. A `language` attribute set in a profile or
in `altcha_widget()` translates them into that language instead of the page's. Languages without a catalog fall back
to English.

### Difficulty

The browser derives a key with `algorithm` and `cost` for every counter from 0 until it finds the secret one the server
picked between `counter_min` and `counter_max`: on average `cost × (counter_min + counter_max) / 2` key derivations,
split over several Web Workers. Measured with WebCrypto, one PBKDF2/SHA-256 derivation at `cost: 1000` takes about
0.15 ms on a recent laptop, so the defaults cost about 0.5 s there and 1–2 s on a cheap phone. Raise the cost for forms
that attract more bots, keep it low where visitors are on slow phones.

The server derives one key at `cost` per challenge it hands out (hence the limits above and the rate limit) and checks a
solution with two HMACs, never with a key derivation.

## What it does and doesn't do

- It makes every automated submission cost CPU time and stops the generic bots behind nearly all form spam and
  login attempts. It can't stop an attacker who is willing to pay for solving challenges, so keep `login_throttling`
  and strong passwords for the admin.
- Only the login **form** is checked. Bolt's default `security.yaml` also lets the `api` firewall take credentials over
  HTTP Basic (`http_basic: ~`), past the login form: remove it when the site doesn't use the API.
- Submissions are checked: requests whose real method or overridden method (`X-HTTP-Method-Override`, `_method`) is
  POST, PUT, PATCH or DELETE. A Symfony form submitted partly (PATCH, `submit($data, false)`) without its ALTCHA field
  fails as well.
- A request is checked once: a route listed under `routes:` that also has `#[RequireAltcha]`, or a `forward()` to a
  protected controller, doesn't check the same solution twice. With the attribute on a controller class and on an
  action, the action's profile wins.
- Every failure is logged at `notice` level with its status and profile, nothing else (no IP, no form data).

## Durability

**Used solutions** are remembered until they expire, in `var/data/bolt-altcha` (outside `var/cache`, so `cache:clear`
and Bolt's "Clear the cache" don't make them reusable), and the check-and-remember step runs under a file lock, so one
solution sent in many parallel requests passes once. On **several servers** behind a load balancer, give both a shared
store, e.g. Redis, by overriding the two services in `config/packages/extension_bolt-altcha.yaml`:

```yaml
services:
  bolt_altcha.replay_pool:
    class: Symfony\Component\Cache\Adapter\RedisAdapter
    arguments: ['@redis_connection', 'bolt_altcha']
  bolt_altcha.lock_factory:
    class: Symfony\Component\Lock\LockFactory
    arguments: ['@lock.redis.store'] # your Redis lock store
```

A store or lock that fails (full disk, unwritable `var/data`, Redis down) is logged as an error and lets the solution
through rather than locking visitors out. That includes a store that can't even be created (`var/data/bolt-altcha`
not writable by the web server, Redis unreachable): the extension creates both on first use, inside the same error
handling, so the rest of the site never depends on them. While the store fails, a solution can be used more than
once: watch the log for these errors.

**Mistakes don't take the site down.** The hooks that run on their own (the login check, fields added to Bolt's forms
and to `forms:`, `routes:`, `#[RequireAltcha]`) switch off, logged as critical, while the config is invalid or no
strong key is set. `cache:clear`/`cache:warmup` fails on the same mistakes, so deploys catch them. Code that uses the
extension on purpose (`altcha_widget()`, `AltchaType` in your form, the `Verifier`) still throws, so you see the error.

**A deploy that skipped `extensions:configure`** leaves the widget files missing or outdated
(`public/assets/altcha/VERSION` tells which version is published). The dashboard then shows a notice, and the login
check stands down (logged as critical) instead of locking every editor out. Run `bin/console extensions:configure`.

To switch everything off in an emergency, put `enabled: false` into
`config/extensions/tomvondracek-altcha_local.yaml`.

The challenge endpoint answers `429` after `challenge_rate_limit` challenges a minute from one client: one IPv4
address, or one IPv6 `/64` network (a single host usually owns a whole `/64`, so counting IPv6 addresses one by one
would be no limit). Behind a reverse proxy, set Symfony's trusted proxies, or all visitors share the proxy's address
and limit; trust only your proxies, or clients can claim any address in `X-Forwarded-For`.

## Privacy

The widget, its workers and the challenge endpoint (`/altcha/challenge/{profile}`) are on your site. ALTCHA sets no
cookie and stores nothing in the browser. The extension stores a hash of each used challenge until it expires, and the
rate limiter counts challenges per IP address for a minute in the application cache. Nothing for the cookie banner; the privacy policy may mention a self-hosted anti-spam check without personal
data.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/ecs check src tests
vendor/bin/rector process -n
```

The widget is vendored in `assets/altcha/` from npm (`altcha`, version in `package.json`): its CSP-friendly external
build and the PBKDF2 and SHA workers. `assets/altcha/bolt-altcha.js` is the extension's own loader. The widget's
texts in `translations/bolt_altcha.*.yaml` started from the widget's own translations; `TranslationsTest` fails when a
new widget version brings texts the catalogs don't have.
To update the widget:

```bash
npm ci && npm run build
```

then bump `WidgetRenderer::ASSETS_VERSION` (the test checks it starts with the npm version) and commit `assets/`.

## License

MIT. The bundled ALTCHA widget is MIT licensed as well (`assets/altcha/LICENSE.txt`).
