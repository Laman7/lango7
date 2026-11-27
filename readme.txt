=== Lango7 ===
Contributors: edwinmasripan
Tags: translation, multilingual, translate, hreflang, chrome translate
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.24
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate your site into SEO-friendly language folders like /fr/ for free, with Chrome's built-in translator. No API key, no duplicate posts.

== Description ==

Lango7 translates the page WordPress has already rendered, so it works with Bricks, Elementor, Gutenberg, Divi and classic themes without per-builder setup. The translation itself is done by the translator built into Google Chrome, on your own computer, for free. Lango7 saves each translated sentence in your database and serves it from there, so every visitor, in any browser, gets a finished, server-rendered page that search engines can index.

* **Free translation, no key.** Open Translate → Free Translate in desktop Chrome (version 138 or later) and click Start. Only new or changed text is translated.
* **Real URLs for search engines.** Every page gets a translated twin at `/fr/…` with `hreflang`, `x-default`, a self-referencing canonical, `<html lang dir>` and core sitemap entries.
* **No duplicate posts.** Nothing is copied into the posts table. Turn the plugin off and your site is exactly as it was.
* **Your data.** Translations live in your database. There is no Lango7 account, subscription or word quota.
* **Cache friendly.** Fully translated pages are cacheable at their own URL; pages with untranslated text are sent with no-store, and the page cache is purged when translations are saved.
* **Fix any sentence on the page.** On a translated page, click **Edit translations** in the admin bar, click a sentence, correct it and save. Links and numbers are locked so they cannot break, and your edit is never overwritten by machine translation.
* **Translations and glossary.** Search every translated sentence, correct it in place, or send it back to be translated again. Your site name is never translated: Lango7 keeps it as it is in every language, automatically.
* **Cost estimator.** Translate → Tools reads every page of your site, counts its words and characters, and shows what translating it costs on each service, before you spend anything.
* **Language switcher anywhere.** A block, a menu item (Appearance → Menus, or one click from the dashboard), a Bricks element, the `[lango7_switcher]` shortcode or `lango7_switcher()` in PHP, all with crawlable links to the same page in each language. Three styles: a list of links, a dropdown, or a globe icon for headers (`[lango7_switcher style="globe"]`). For your own styling, `style="ul"` or `style="ol"` gives a bare list with no Lango7 CSS: each link holds `<span class="iso_name">` (ES, or SPA with `names="iso3"`) and `<span class="full_name">` (Español, or Spanish with `names="name"`).

Add as many languages as you like from the 47 that Chrome's translator supports (Chinese, French, German, Hindi, Indonesian, Japanese, Korean, Spanish, Thai, Vietnamese and more). Text added after you translate shows in the site language until you run Free Translate again.

**Lango7 Pro** (a separate plugin) adds translation services with your own key (Google Cloud Translation, DeepL, OpenAI, Claude, Gemini or any OpenAI-compatible service), a glossary for any keyword, Bulk translate with the cost shown first, and translated page addresses, so new pages are translated on their first visit, and languages Chrome cannot translate, such as Malay, Filipino, Persian and Urdu.

== External services ==

Lango7 does not send your content to any external service.

Lango7 news (off by default): if an administrator turns it on, on the Lango7 dashboard or in Translate → Settings → News and uninstall, the dashboard shows one offer and the two latest blog posts from lango7.com, the plugin author's site. To do this it reads two public RSS feeds, https://lango7.com/category/promo/feed/ and https://lango7.com/feed/, at most twice a day, and only when an administrator opens the dashboard. Nothing is requested until it is turned on. Nothing about your site or its visitors is sent; the request carries only what every web request does (your server's IP address and WordPress version in the user agent). Terms: https://lango7.com/terms Privacy policy: https://lango7.com/privacy

Free Translate uses the Translator API built into Google Chrome. The translation runs on your computer; Chrome downloads its language model from Google the first time a language pair is used, under Chrome's own terms. Page text is sent only between your browser and your own site.

== Installation ==

1. Install and activate. The setup screen (Translate in the admin menu) opens.
2. Choose your site's language and the language to translate into, and click Continue.
3. On **Free Translate**, in desktop Chrome 138 or later, click **Start translating**. Keep the tab open until it finishes.
4. Visit `/fr/` (or your language's folder) and add a language switcher from the dashboard.

== Frequently Asked Questions ==

= Do my visitors need Chrome? =

No. Only you need Chrome, to translate. Visitors get ordinary translated HTML from your server in any browser, and so do search crawlers.

= Why is my language missing? =

The free version lists only languages Chrome's built-in translator supports. Malay, Filipino, Khmer, Lao, Burmese, Persian and Urdu need a server engine (Lango7 Pro).

= Does Free Translate work on my phone? =

Not yet: Chrome's built-in translator is available on desktop (Windows, macOS, Linux, ChromeOS), not on Android or iOS.

= How do I keep text from being translated? =

Your site name is kept as it is automatically (Lango7 Pro's glossary adds any other names and terms). For whole elements, add `translate="no"` or the class `notranslate`, or list CSS selectors under "Do not translate elements" in Settings.

= What happens to the translations if I uninstall? =

They are kept unless you choose "Delete all translations and settings" in the settings first.

== Developers ==

Filters and actions: `lango7_engines` (register an engine), `lango7_available_languages`, `lango7_engine_for_language`, `lango7_settings_engine`, `lango7_settings_saved`, `lango7_settings_payload`, `lango7_settings_disclosure`, `lango7_show_pro_card`, `lango7_max_languages`, `lango7_skip_request`, `lango7_engine_allowed`, `lango7_translate_on_visit`, `lango7_translate_now_url`, `lango7_translate_all_url`, `lango7_translate_now_status`, `lango7_post_language_notes`, `lango7_translation_replacing`, `lango7_strings_deleted`, `lango7_strings_page_after`, `lango7_setup_services`, `lango7_setup_engine`, `lango7_setup_mode`, `lango7_setup_saved`, `lango7_setup_first_action`, `lango7_language_added_action`, `lango7_language_catalog`, `lango7_skip_selectors`, `lango7_translatable_attributes`, `lango7_hreflang`, `lango7_engine_code`, `lango7_before_apply`, `lango7_translations_saved`, `lango7_purge_url`, `lango7_purge_all`, `lango7_loaded`.

WP-CLI: `wp lango7 status`, `wp lango7 translate <url> [--lang=]`, `wp lango7 process-queue`, `wp lango7 purge`, `wp lango7 test-engine`, `wp lango7 strings list [--lang=] [--status=]`.

Theme functions: `lango7_current_language()` (the language code, null on the site's own language), `lango7_url( $lang, $url = null )` (this page or a URL in a language), `lango7_languages()` (target languages with code, prefix, names, direction and this page's URL), `lango7_switcher( $args )`.

Show content in some languages only: `[lango7_lang code="ms,zh-Hans"]…[/lango7_lang]`, or hide it with `[lango7_lang not="ms"]…[/lango7_lang]`; `source` means the site's own language. Hidden content is not on the page, so it is not translated either.

Every page's `<body>` gets `lango7-lang-{code}`, and translated pages also get `lango7-translated`, for language-specific CSS. In Bricks, the dynamic tag `{lango7_language}` gives the current language code.

== Changelog ==

= 1.24 =
* Fixed: Settings → Languages showed "Also delete its 0 translations" when removing a language, and logged a PHP warning. It now shows the real number.

= 1.23 =
* Version number now matches Lango7 Pro 1.23. No other changes.

= 1.22 =
* Fixed: a translation that dropped the space beside a number or link ("1,000perkataan") gets it back, including translations already saved.
* New "Don't translate this page": switch translation off for one post or page from the front-end language menu (under Translate this page) or from Posts and Pages (row action and bulk action), or with the switch in the block editor's Lango7 sidebar. On archives and other addresses without a post, the menu item adds the address to Do not translate URLs, and Translate this page again removes it. It is stored on the post, so it follows a changed address, and visitors see the page in the original language. Translations are kept and come back with Translate again. The Do not translate URLs list in Settings still works for paths and wildcards.
* Translations: the language buttons and the filters are now two rows. New "Translated by" filter shows only what one service translated (Free Translate, Google, DeepL, an AI model) or what was edited by hand.
* Settings → Switcher: new Language order card. Drag languages into the order the switcher shows them, as in Menus (or use the arrow buttons). Applies to every switcher style, menus, the block and Bricks.
* Overview setup list checks the real state: a language added, a translation engine with a key (new step), pages translated, a switcher placed, keywords added to the glossary, a hand edit made, and the site brief written (with Lango7 Pro). It opens while steps are left.
* Edit translations on the page: slider arrows, dots, tabs and toggles work while editing; hold Alt (⌥) and click to use any part of the page. Page strings has a new "Not on screen" group for text in other slides, closed tabs and menus, which opens without scrolling the page.
* The admin bar language menu shows only on the front end, with "View in" every language and Translate this page; "Go to Lango7 - Translate" is under Edit translations.
* Pages whose address redirects (such as a missing trailing slash) translate instead of failing with HTTP 301.
* Settings is now settings only: the Status sidebar and the progress bars are gone from the Languages, Translation Engine and Limits tabs. Progress lives on the Overview as "87% translated · 314 to go". New Settings → Switcher tab shows every switcher shortcode with a live preview. "Retranslate everything" moved to Tools → Retranslate, for one language or all, with a confirmation.
* Block editor: a "Lango7 Translations" panel in the post sidebar shows each language's progress, with "View <language>" (opens the translated page in a new tab), "Edit translations", and Translate now with your chosen service.
* Language switcher: new "Globe icon" style, a globe button that opens the list of languages (block, Bricks element, or `[lango7_switcher style="globe"]`). Open dropdowns now close on a click outside them or on Escape.
* Languages can be kept as drafts by Lango7 Pro: hidden from visitors, the language switcher, hreflang and sitemaps.
* Support for Lango7 Pro's approval workflow: an Approvals tab on Translations (always shown for languages that require approval) with Approve selected, Approve in the front-end editor, and Approve page in the admin bar.
* New Translate → Activity: every translation, edit, approval and error, newest first, with filters by type and language and a Clear log button. Kept for 30 days (filter `lango7_activity_days`).
* New Tools → Not Translated: every page with text missing in a language, with how much is missing and a Free Translate button per page. Built from pages Lango7 already read; a page you publish or update is read again in the background. The Dashboard shows the count.
* Block editor: Lango7 now has its own sidebar (the Lango7 Translation button at the top right). Click a language to view the page, edit it on the page, or translate the page with the service you pick. Drafts can be translated before they are published. Delete Translation removes a language's translation of one page; text shared with other pages, such as the menu, is kept.
* Edit translations on the page: new "Keep original" button shows the original text for a sentence instead of a translation, and it is not translated again.
* Right-to-left languages (Arabic, Hebrew, Persian, Urdu) keep your site's layout: only the text runs right to left, so menus, columns and images stay where they are. Use the `lango7_rtl_layout` filter to mirror the whole page instead.
* Developer hooks: `lango7_language_live`, `lango7_machine_status`, `lango7_hide_unapproved`, `lango7_can_approve`, `lango7_approved`, `lango7_editor_panel_data`, `lango7_rtl_layout`.

= 1.21 =
* The Add a language dialog lists only the languages Free Translate can translate. Lango7 Pro adds the rest.
* Translate → Tools shows the Cost estimator; Lango7 Pro adds its own tools there.
* Setup shows Free Translate on its own; translation services appear when Lango7 Pro is installed. The Cost estimator notes which services come with Pro instead of badging each one.
* Every Lango7 screen has the same header bar with the Lango7 logo and the page name.
* The Posts and Pages language column loads its stylesheet only on those lists.
* Code checks: translator comments, sanitized input and prepared database queries throughout.

= 1.09 =
* Add as many languages as you like: the one-language limit is gone.
* Your site name is now protected automatically in every language. The keyword glossary moved to Lango7 Pro; Translate → Glossary shows the protected site name.
* Tools: Translated URLs and Categories and tags tabs (Lango7 Pro).
* Engines can be given a site brief (Lango7 Pro's Site brief), and the cost estimator lists Google Cloud Translation Basic and Advanced.
* Lango7 news is now off until an administrator turns it on (Show news on the dashboard, or in Settings). Nothing is requested from lango7.com before that.
* Security: server and request values are sanitized before use, and Free Translate's REST routes check the per-language permission.
* The Posts and Pages language column loads its styles as a stylesheet, and the editor's page data uses WordPress's script-tag helper.

= 1.08 =
* New Translate → Tools screen, with a tab per tool in the Settings style: Shortcodes, Cost estimator, Images and Import / export. The Cost estimator reads every public page of your site, counts its sentences, words and characters, and prices the text on Free Translate, Google, DeepL and each AI model, for one language, all your languages, and what is not translated yet. Shortcodes, Images and Import / export are Lango7 Pro tools; Free shows what each does.
* Front-end editor: Translate now in the edit box translates a sentence again and saves it. The language's own service is chosen; pick Free Translate or another set-up service instead. Revert brings back the translation it replaced, your edit included.
* Front-end editor: the button that drops an edit is now called Remove my edit.
* Front-end editor: style marks (bold, italic, styled text, line breaks) can be deleted to drop that style; links, images, buttons and numbers still can't. New Bold and Italic buttons (Ctrl/⌘ + B, Ctrl/⌘ + I) and line breaks (Shift + Enter) add plain formatting of your own. Marks have clearer names ("styled text" instead of "text"), tooltips, a ? explanation, and hovering one highlights its partner.

= 1.07 =
* The glossary's example term is your site's name.
* Lango7's admin screens show the version and a Help and docs link at the bottom.
* The dashboard shows language cards two per row.
* New Settings → Debug tab: the error log one line per entry, with Clear log and a Don't log errors switch.
* Glossary redesigned around keywords: Add keywords (paste lines or upload a CSV), a How the glossary works guide, and the Protected keywords list with Edit and Delete in place.
* Glossary Retranslate: finds translations that don't follow your keywords yet, shows how many characters it will cost, and redoes only those strings. Visitors keep seeing the current translation until the new one is ready (in the background with a server engine, or on the next Free Translate run). A failed redo keeps the current translation.
* Settings saves show a confirmation with the time next to the Save button, which now stays on screen.
* Testing an engine key uses the language's own engine code.
* Admin scripts and styles are versioned by file, so browsers load changed files right away instead of a cached copy.

= 1.04 =
* The shared Translations page now gives Pro the same one-click Empty action and in-place Translate again behavior as Free, without Undo edit or an empty-save warning.
* Server-engine row retranslation validates the new result before replacing the old translation, including human edits. A failed attempt keeps the old result.

= 1.03 =
* Free Translations re-translates a selected row on the page through desktop Chrome, including saved edits. The previous result stays until a valid replacement is saved.
* Free Translations adds a one-click Empty action beside Edit and Edit on page. It saves an empty translation without a warning or extra Save step. The Undo edit action is removed from Free.
* Pro add-on files and version are unchanged.

= 1.0.2 =
* Translations now lets an editor save an intentionally empty translation after a confirmation warning. The selected text is hidden on translated pages; Undo edit restores it.

= 1.0.1 =
* Free Translate shows progress as soon as Start is clicked, including while Chrome prepares or downloads its language model.
* Translations has a per-row Translate again action. Free opens Chrome for the selected string and keeps the old translation until Start is clicked; Pro uses the language's configured service. Edited wording stays protected until Undo edit.

= 1.0 =
* First 1.0 release of Free Translate, language setup, translations, glossary, and the front-end editor.
* Setup opens when no target language is configured and after reactivation with zero target languages. "Finish later" dismisses it for the current activation.

= 0.1.1 =
* 141 languages, up from 47, including regional versions (English UK/US/Australia, Spanish Mexico/Latin America, French Canada, Portuguese Portugal) and Cantonese. Free Translate (Chrome) can do 47 of them; the rest need a translation service (Lango7 Pro).
* A service that can't translate a language is greyed out for it, with the reason, and saving such a choice is refused. Languages no service of yours can translate can't be added.
* Settings → Languages is easier to use: each language is listed with its address, progress and service, and "Add a language" opens a search (type French or Français) and then the address to check. The language is saved at once and the page shows the next step. Edit a language to change its address or service, or remove it.
* New setup: choose your languages and how to translate them (Free Translate, or a translation service with Lango7 Pro). It opens the first time and again from Settings → Run setup again, and ends on the dashboard with "Translate your homepage now".
* Edit mode lets add-ons' dialogs receive clicks.
* "Languages" column in Posts, Pages and other public post type lists: how much of each page is translated per language, with "Translate now" for pages that aren't finished.
* Language menu in the admin bar: shows the language you are viewing and links to the same page in every language (drafts included, to preview them). A dot means some text on the page is not translated yet, with "Translate this page" in the menu.
* Free Translate can translate just one page.
* When a run finishes and no supported cache plugin is active, a reminder to clear the page cache or CDN.
* Theme functions `lango7_current_language()`, `lango7_url()` and `lango7_languages()`; `[lango7_lang]` shortcode; Bricks `{lango7_language}` tag.
* Language classes on `<body>`.
* Suggested privacy policy text.

= 0.1.0 =
* First development release: routing, translation with Chrome's built-in translator, front-end editor, SEO tags, sitemap, setup wizard and dashboard, strings screen, glossary, language switcher (block, menu item, Bricks element, shortcode), settings screen.
