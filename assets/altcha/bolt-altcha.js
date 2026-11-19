/*
 * bolt-altcha loader (tomvondracek/bolt-altcha). Not part of the ALTCHA package: `npm run build`
 * leaves this file alone.
 *
 * Loads the widget's "external" build, which contains no inline workers, and registers the
 * PBKDF2 and SHA workers as files of this site, so a strict Content-Security-Policy needs nothing
 * beyond 'self' (no blob: workers). Then hands the widget its texts: WidgetRenderer prints them,
 * translated by Symfony, as <script type="application/json" data-bolt-altcha-i18n="<language>">.
 * Module scripts run after the page is parsed, so every such element is there.
 *
 * Query parameter of this script's URL (set by WidgetRenderer):
 *   v  cache buster, passed on to every file loaded from here
 *
 * The widget starts verifying no sooner than 10 ms after it is mounted and fetches its challenge
 * first, so the workers registered right after the import are always in place in time.
 */
const here = new URL(import.meta.url);
const version = here.searchParams.get('v');
const suffix = version ? `?v=${encodeURIComponent(version)}` : '';
const asset = (path) => new URL(path + suffix, here).href;

await import(asset('./altcha.js'));

const worker = (file) => () => new Worker(asset(`./workers/${file}`));
for (const algorithm of ['PBKDF2/SHA-256', 'PBKDF2/SHA-384', 'PBKDF2/SHA-512']) {
    globalThis.$altcha.algorithms.set(algorithm, worker('pbkdf2.js'));
}
for (const algorithm of ['SHA-256', 'SHA-384', 'SHA-512']) {
    globalThis.$altcha.algorithms.set(algorithm, worker('sha.js'));
}

const { i18n } = globalThis.$altcha;
for (const element of document.querySelectorAll('script[type="application/json"][data-bolt-altcha-i18n]')) {
    const language = element.dataset.boltAltchaI18n;
    try {
        i18n.set(language, { ...(i18n.get(language) ?? {}), ...JSON.parse(element.textContent) });
    } catch (error) {
        console.error('bolt-altcha: unreadable texts for "%s"', language, error);
    }
}
