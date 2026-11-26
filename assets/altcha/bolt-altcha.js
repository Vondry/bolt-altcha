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
 *
 * Trusted Types: Svelte renders the widget's {@html} (the footer with its ALTCHA link, also with
 * `hideFooter`) by assigning a plain string to innerHTML, past the widget's own
 * `svelte-trusted-html` policy. Under `require-trusted-types-for 'script'` that assignment throws,
 * the widget breaks while it mounts and never adds its field, so no protected form can be sent.
 * `new Worker(url)` is a Trusted Types sink as well. A `default` policy, created before the
 * widget, lets through the widget's texts and the URLs of the two workers below, nothing else.
 * The page's CSP must allow it: `trusted-types svelte-trusted-html default`.
 */
const here = new URL(import.meta.url);
const version = here.searchParams.get('v');
const suffix = version ? `?v=${encodeURIComponent(version)}` : '';
const asset = (path) => new URL(path + suffix, here).href;
const workerUrls = new Set(['pbkdf2.js', 'sha.js'].map((file) => asset(`./workers/${file}`)));

const widgetTexts = () => {
    let languages = {};
    globalThis.$altcha?.i18n?.store?.subscribe((value) => { languages = value ?? {}; })();

    return new Set(Object.values(languages).flatMap((texts) => Object.values(texts ?? {})));
};
if (globalThis.trustedTypes?.createPolicy && !globalThis.trustedTypes.defaultPolicy) {
    try {
        globalThis.trustedTypes.createPolicy('default', {
            // null refuses the assignment, as without a default policy.
            createHTML: (html) => (widgetTexts().has(html) ? html : null),
            createScriptURL: (url) => (workerUrls.has(url) ? url : null),
        });
    } catch (error) {
        console.warn("bolt-altcha: the CSP does not allow the Trusted Types policy 'default'; where it enforces Trusted Types (require-trusted-types-for), the widget can't render. Allow it: trusted-types svelte-trusted-html default", error);
    }
}

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
