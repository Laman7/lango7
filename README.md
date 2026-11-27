# Lango7 - Free WordPress Translation

**Translate your WordPress site into SEO-friendly language folders like `/fr/`, for free, with Chrome's built-in translator. No API key, no duplicate posts.**

Lango7 translates the page WordPress has already rendered, so it works with Bricks, Elementor, Gutenberg, Divi and classic themes without any per-builder setup. The translation runs in Google Chrome on your own computer, at no cost. Each translated sentence is saved in your database and served from there, so every visitor, in any browser, gets a finished page that search engines can index.

## Download

**[⬇ Download Lango7 v1.23](https://github.com/Laman7/lango7/releases/tag/v1.23)**

On the release page, under **Assets**, download **`lango7-v1.23.zip`**. Don't use "Source code (zip)": that's the raw repository, not the installable plugin.

Then in WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, and click **Install Now** and **Activate**.

## Requirements

| | |
|---|---|
| WordPress | 6.5 or later |
| PHP | 8.1 or later |
| To translate | Desktop Chrome 138 or later (Windows, macOS, Linux, ChromeOS) |
| Visitors | Any browser |

## Quick start

1. Activate the plugin. The setup screen (**Translate** in the admin menu) opens.
2. Choose your site's language and the language to translate into, then click **Continue**.
3. On **Free Translate**, in desktop Chrome, click **Start translating**. Keep the tab open until it finishes.
4. Visit `/fr/` (or your language's folder) and add a language switcher from the dashboard.

## Features

- **Free translation, no key.** Only new or changed text is translated.
- **Real URLs for search engines.** Every page gets a translated twin at `/fr/…` with `hreflang`, `x-default`, a self-referencing canonical, `<html lang dir>` and sitemap entries.
- **No duplicate posts.** Nothing is copied into the posts table. Turn the plugin off and your site is exactly as it was.
- **Your data.** Translations live in your database. No account, subscription or word quota.
- **Edit on the page.** Click **Edit translations** in the admin bar, click a sentence, fix it and save. Links and numbers are locked, and your edit is never overwritten.
- **Don't translate a page.** Switch translation off for any page from the admin bar, the post list or the block editor sidebar.
- **Translations screen.** Search every sentence, filter by language, status or service, and edit in place.
- **Cost estimator.** See what translating your whole site would cost on each paid service, before spending anything.
- **Cache friendly.** Fully translated pages are cacheable at their own URL, and the cache is purged when translations change.
- **Language switcher anywhere.** A block, a menu item, a Bricks element, the `[lango7_switcher]` shortcode or `lango7_switcher()` in PHP. Choose a list, a dropdown or a globe icon, and drag the languages into your own order.
- **Right-to-left languages** keep your layout; only the text direction changes.

You can add as many languages as you like from the 47 that Chrome's translator supports, including Chinese, French, German, Hindi, Indonesian, Japanese, Korean, Spanish, Thai and Vietnamese.

## FAQ

**Do my visitors need Chrome?**
No. Only you need Chrome, to translate. Visitors and search crawlers get ordinary translated HTML in any browser.

**Does Free Translate work on my phone?**
Not yet. Chrome's built-in translator is available on desktop only.

**How do I keep text from being translated?**
Your site name is kept automatically. For whole elements, add `translate="no"` or the class `notranslate`, or list CSS selectors under **Do not translate elements** in Settings.

**What happens to translations if I uninstall?**
They are kept, unless you choose **Delete all translations and settings** in Settings first.

## Privacy

Lango7 doesn't send your content to any external service. Chrome downloads its language model from Google the first time a language pair is used. Page text goes only between your browser and your own site.

## For developers

- **WP-CLI:** `wp lango7 status`, `wp lango7 translate <url> [--lang=]`, `wp lango7 process-queue`, `wp lango7 purge`, `wp lango7 strings list`
- **Theme functions:** `lango7_current_language()`, `lango7_url( $lang, $url = null )`, `lango7_languages()`, `lango7_switcher( $args )`
- **Language-only content:** `[lango7_lang code="fr,de"]…[/lango7_lang]` or `[lango7_lang not="fr"]…[/lango7_lang]`
- **CSS:** `<body>` gets `lango7-lang-{code}`, and translated pages also get `lango7-translated`.
- **Hooks:** see the Developers section of [readme.txt](readme.txt).

## Changelog

See the [Releases](https://github.com/Laman7/lango7/releases) page and [readme.txt](readme.txt).

## Licence

GPLv2 or later. More at [lango7.com](https://lango7.com).
