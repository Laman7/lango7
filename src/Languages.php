<?php
/**
 * Language catalogue and the enabled languages.
 *
 * @package Lango7
 */

declare( strict_types=1 );

namespace Lango7;

final class Languages {

	public const OPTION = 'lango7_languages';

	/** URL folder => language code, for languages that were turned off (their old URLs redirect). */
	public const REMOVED_OPTION = 'lango7_removed_prefixes';

	/** @var Language[]|null */
	private ?array $enabled = null;

	public function __construct( private Settings $settings ) {}

	/**
	 * Every language Lango7 knows. prefix is the default URL folder, google the
	 * Cloud Translation code, chrome the code for Chrome's built-in Translator
	 * API ('' when Chrome cannot translate it).
	 *
	 * @return array<string, array{name: string, native: string, prefix: string, google: string, chrome: string, dir: string}>
	 */
	public static function catalog(): array {
		$rows = array(
			// code, English name, native name, default prefix, Google code, Chrome code, direction.
			array( 'en', 'English', 'English', 'en', 'en', 'en', 'ltr' ),
			array( 'ms', 'Malay', 'Bahasa Melayu', 'ms', 'ms', '', 'ltr' ),
			array( 'zh-Hans', 'Chinese (Simplified)', '简体中文', 'cn', 'zh-CN', 'zh', 'ltr' ),
			array( 'zh-Hant', 'Chinese (Traditional)', '繁體中文', 'tw', 'zh-TW', 'zh-Hant', 'ltr' ),
			array( 'id', 'Indonesian', 'Bahasa Indonesia', 'id', 'id', 'id', 'ltr' ),
			array( 'ta', 'Tamil', 'தமிழ்', 'ta', 'ta', 'ta', 'ltr' ),
			array( 'th', 'Thai', 'ไทย', 'th', 'th', 'th', 'ltr' ),
			array( 'vi', 'Vietnamese', 'Tiếng Việt', 'vi', 'vi', 'vi', 'ltr' ),
			array( 'fil', 'Filipino', 'Filipino', 'fil', 'tl', '', 'ltr' ),
			array( 'km', 'Khmer', 'ខ្មែរ', 'km', 'km', '', 'ltr' ),
			array( 'lo', 'Lao', 'ລາວ', 'lo', 'lo', '', 'ltr' ),
			array( 'my', 'Burmese', 'မြန်မာ', 'my', 'my', '', 'ltr' ),
			array( 'hi', 'Hindi', 'हिन्दी', 'hi', 'hi', 'hi', 'ltr' ),
			array( 'bn', 'Bengali', 'বাংলা', 'bn', 'bn', 'bn', 'ltr' ),
			array( 'mr', 'Marathi', 'मराठी', 'mr', 'mr', 'mr', 'ltr' ),
			array( 'te', 'Telugu', 'తెలుగు', 'te', 'te', 'te', 'ltr' ),
			array( 'kn', 'Kannada', 'ಕನ್ನಡ', 'kn', 'kn', 'kn', 'ltr' ),
			array( 'ja', 'Japanese', '日本語', 'ja', 'ja', 'ja', 'ltr' ),
			array( 'ko', 'Korean', '한국어', 'ko', 'ko', 'ko', 'ltr' ),
			array( 'ar', 'Arabic', 'العربية', 'ar', 'ar', 'ar', 'rtl' ),
			array( 'he', 'Hebrew', 'עברית', 'he', 'iw', 'he', 'rtl' ),
			array( 'fa', 'Persian', 'فارسی', 'fa', 'fa', '', 'rtl' ),
			array( 'ur', 'Urdu', 'اردو', 'ur', 'ur', '', 'rtl' ),
			array( 'tr', 'Turkish', 'Türkçe', 'tr', 'tr', 'tr', 'ltr' ),
			array( 'fr', 'French', 'Français', 'fr', 'fr', 'fr', 'ltr' ),
			array( 'de', 'German', 'Deutsch', 'de', 'de', 'de', 'ltr' ),
			array( 'es', 'Spanish', 'Español', 'es', 'es', 'es', 'ltr' ),
			array( 'pt', 'Portuguese', 'Português', 'pt', 'pt', 'pt', 'ltr' ),
			array( 'pt-BR', 'Portuguese (Brazil)', 'Português (Brasil)', 'br', 'pt', 'pt', 'ltr' ),
			array( 'it', 'Italian', 'Italiano', 'it', 'it', 'it', 'ltr' ),
			array( 'nl', 'Dutch', 'Nederlands', 'nl', 'nl', 'nl', 'ltr' ),
			array( 'pl', 'Polish', 'Polski', 'pl', 'pl', 'pl', 'ltr' ),
			array( 'ru', 'Russian', 'Русский', 'ru', 'ru', 'ru', 'ltr' ),
			array( 'uk', 'Ukrainian', 'Українська', 'uk', 'uk', 'uk', 'ltr' ),
			array( 'cs', 'Czech', 'Čeština', 'cs', 'cs', 'cs', 'ltr' ),
			array( 'sk', 'Slovak', 'Slovenčina', 'sk', 'sk', 'sk', 'ltr' ),
			array( 'sl', 'Slovenian', 'Slovenščina', 'sl', 'sl', 'sl', 'ltr' ),
			array( 'hr', 'Croatian', 'Hrvatski', 'hr', 'hr', 'hr', 'ltr' ),
			array( 'bg', 'Bulgarian', 'Български', 'bg', 'bg', 'bg', 'ltr' ),
			array( 'el', 'Greek', 'Ελληνικά', 'el', 'el', 'el', 'ltr' ),
			array( 'ro', 'Romanian', 'Română', 'ro', 'ro', 'ro', 'ltr' ),
			array( 'hu', 'Hungarian', 'Magyar', 'hu', 'hu', 'hu', 'ltr' ),
			array( 'lt', 'Lithuanian', 'Lietuvių', 'lt', 'lt', 'lt', 'ltr' ),
			array( 'sv', 'Swedish', 'Svenska', 'sv', 'sv', 'sv', 'ltr' ),
			array( 'da', 'Danish', 'Dansk', 'da', 'da', 'da', 'ltr' ),
			array( 'fi', 'Finnish', 'Suomi', 'fi', 'fi', 'fi', 'ltr' ),
			array( 'nb', 'Norwegian', 'Norsk bokmål', 'no', 'no', 'no', 'ltr' ),
			// Regional versions. Chrome and Google translate into the base language.
			array( 'en-GB', 'English (UK)', 'English (UK)', 'en-gb', 'en', 'en', 'ltr' ),
			array( 'en-US', 'English (US)', 'English (US)', 'en-us', 'en', 'en', 'ltr' ),
			array( 'en-AU', 'English (Australia)', 'English (Australia)', 'en-au', 'en', 'en', 'ltr' ),
			array( 'es-MX', 'Spanish (Mexico)', 'Español (México)', 'es-mx', 'es', 'es', 'ltr' ),
			array( 'es-419', 'Spanish (Latin America)', 'Español (Latinoamérica)', 'es-419', 'es', 'es', 'ltr' ),
			array( 'fr-CA', 'French (Canada)', 'Français (Canada)', 'fr-ca', 'fr', 'fr', 'ltr' ),
			array( 'pt-PT', 'Portuguese (Portugal)', 'Português (Portugal)', 'pt-pt', 'pt-PT', 'pt', 'ltr' ),
			array( 'yue', 'Cantonese', '粵語', 'yue', 'yue', '', 'ltr' ),
			// Languages Chrome's built-in translator cannot do: they need a translation service.
			array( 'af', 'Afrikaans', 'Afrikaans', 'af', 'af', '', 'ltr' ),
			array( 'sq', 'Albanian', 'Shqip', 'sq', 'sq', '', 'ltr' ),
			array( 'am', 'Amharic', 'አማርኛ', 'am', 'am', '', 'ltr' ),
			array( 'hy', 'Armenian', 'Հայերեն', 'hy', 'hy', '', 'ltr' ),
			array( 'as', 'Assamese', 'অসমীয়া', 'as', 'as', '', 'ltr' ),
			array( 'ay', 'Aymara', 'Aymar aru', 'ay', 'ay', '', 'ltr' ),
			array( 'az', 'Azerbaijani', 'Azərbaycan dili', 'az', 'az', '', 'ltr' ),
			array( 'bm', 'Bambara', 'Bamanankan', 'bm', 'bm', '', 'ltr' ),
			array( 'eu', 'Basque', 'Euskara', 'eu', 'eu', '', 'ltr' ),
			array( 'be', 'Belarusian', 'Беларуская', 'be', 'be', '', 'ltr' ),
			array( 'bho', 'Bhojpuri', 'भोजपुरी', 'bho', 'bho', '', 'ltr' ),
			array( 'bs', 'Bosnian', 'Bosanski', 'bs', 'bs', '', 'ltr' ),
			array( 'ca', 'Catalan', 'Català', 'ca', 'ca', '', 'ltr' ),
			array( 'ceb', 'Cebuano', 'Cebuano', 'ceb', 'ceb', '', 'ltr' ),
			array( 'ny', 'Chichewa', 'Chichewa', 'ny', 'ny', '', 'ltr' ),
			array( 'co', 'Corsican', 'Corsu', 'co', 'co', '', 'ltr' ),
			array( 'dv', 'Dhivehi', 'ދިވެހި', 'dv', 'dv', '', 'rtl' ),
			array( 'doi', 'Dogri', 'डोगरी', 'doi', 'doi', '', 'ltr' ),
			array( 'eo', 'Esperanto', 'Esperanto', 'eo', 'eo', '', 'ltr' ),
			array( 'et', 'Estonian', 'Eesti', 'et', 'et', '', 'ltr' ),
			array( 'ee', 'Ewe', 'Eʋegbe', 'ee', 'ee', '', 'ltr' ),
			array( 'fy', 'Frisian', 'Frysk', 'fy', 'fy', '', 'ltr' ),
			array( 'gl', 'Galician', 'Galego', 'gl', 'gl', '', 'ltr' ),
			array( 'ka', 'Georgian', 'ქართული', 'ka', 'ka', '', 'ltr' ),
			array( 'gn', 'Guarani', 'Avañeʼẽ', 'gn', 'gn', '', 'ltr' ),
			array( 'gu', 'Gujarati', 'ગુજરાતી', 'gu', 'gu', '', 'ltr' ),
			array( 'ht', 'Haitian Creole', 'Kreyòl ayisyen', 'ht', 'ht', '', 'ltr' ),
			array( 'ha', 'Hausa', 'Hausa', 'ha', 'ha', '', 'ltr' ),
			array( 'haw', 'Hawaiian', 'ʻŌlelo Hawaiʻi', 'haw', 'haw', '', 'ltr' ),
			array( 'hmn', 'Hmong', 'Hmoob', 'hmn', 'hmn', '', 'ltr' ),
			array( 'is', 'Icelandic', 'Íslenska', 'is', 'is', '', 'ltr' ),
			array( 'ig', 'Igbo', 'Igbo', 'ig', 'ig', '', 'ltr' ),
			array( 'ilo', 'Ilocano', 'Ilokano', 'ilo', 'ilo', '', 'ltr' ),
			array( 'ga', 'Irish', 'Gaeilge', 'ga', 'ga', '', 'ltr' ),
			array( 'jv', 'Javanese', 'Basa Jawa', 'jv', 'jw', '', 'ltr' ),
			array( 'kk', 'Kazakh', 'Қазақ тілі', 'kk', 'kk', '', 'ltr' ),
			array( 'rw', 'Kinyarwanda', 'Ikinyarwanda', 'rw', 'rw', '', 'ltr' ),
			array( 'gom', 'Konkani', 'कोंकणी', 'gom', 'gom', '', 'ltr' ),
			array( 'kri', 'Krio', 'Krio', 'kri', 'kri', '', 'ltr' ),
			array( 'ku', 'Kurdish (Kurmanji)', 'Kurdî', 'ku', 'ku', '', 'ltr' ),
			array( 'ckb', 'Kurdish (Sorani)', 'کوردی', 'ckb', 'ckb', '', 'rtl' ),
			array( 'ky', 'Kyrgyz', 'Кыргызча', 'ky', 'ky', '', 'ltr' ),
			array( 'la', 'Latin', 'Latina', 'la', 'la', '', 'ltr' ),
			array( 'lv', 'Latvian', 'Latviešu', 'lv', 'lv', '', 'ltr' ),
			array( 'ln', 'Lingala', 'Lingála', 'ln', 'ln', '', 'ltr' ),
			array( 'lg', 'Luganda', 'Luganda', 'lg', 'lg', '', 'ltr' ),
			array( 'lb', 'Luxembourgish', 'Lëtzebuergesch', 'lb', 'lb', '', 'ltr' ),
			array( 'mk', 'Macedonian', 'Македонски', 'mk', 'mk', '', 'ltr' ),
			array( 'mai', 'Maithili', 'मैथिली', 'mai', 'mai', '', 'ltr' ),
			array( 'mg', 'Malagasy', 'Malagasy', 'mg', 'mg', '', 'ltr' ),
			array( 'ml', 'Malayalam', 'മലയാളം', 'ml', 'ml', '', 'ltr' ),
			array( 'mt', 'Maltese', 'Malti', 'mt', 'mt', '', 'ltr' ),
			array( 'mi', 'Māori', 'Te Reo Māori', 'mi', 'mi', '', 'ltr' ),
			array( 'lus', 'Mizo', 'Mizo ṭawng', 'lus', 'lus', '', 'ltr' ),
			array( 'mn', 'Mongolian', 'Монгол', 'mn', 'mn', '', 'ltr' ),
			array( 'ne', 'Nepali', 'नेपाली', 'ne', 'ne', '', 'ltr' ),
			array( 'or', 'Odia', 'ଓଡ଼ିଆ', 'or', 'or', '', 'ltr' ),
			array( 'om', 'Oromo', 'Afaan Oromoo', 'om', 'om', '', 'ltr' ),
			array( 'ps', 'Pashto', 'پښتو', 'ps', 'ps', '', 'rtl' ),
			array( 'pa', 'Punjabi', 'ਪੰਜਾਬੀ', 'pa', 'pa', '', 'ltr' ),
			array( 'qu', 'Quechua', 'Runasimi', 'qu', 'qu', '', 'ltr' ),
			array( 'sm', 'Samoan', 'Gagana Sāmoa', 'sm', 'sm', '', 'ltr' ),
			array( 'sa', 'Sanskrit', 'संस्कृतम्', 'sa', 'sa', '', 'ltr' ),
			array( 'gd', 'Scottish Gaelic', 'Gàidhlig', 'gd', 'gd', '', 'ltr' ),
			array( 'nso', 'Sepedi', 'Sesotho sa Leboa', 'nso', 'nso', '', 'ltr' ),
			array( 'sr', 'Serbian', 'Српски', 'sr', 'sr', '', 'ltr' ),
			array( 'st', 'Sesotho', 'Sesotho', 'st', 'st', '', 'ltr' ),
			array( 'sn', 'Shona', 'ChiShona', 'sn', 'sn', '', 'ltr' ),
			array( 'sd', 'Sindhi', 'سنڌي', 'sd', 'sd', '', 'rtl' ),
			array( 'si', 'Sinhala', 'සිංහල', 'si', 'si', '', 'ltr' ),
			array( 'so', 'Somali', 'Soomaali', 'so', 'so', '', 'ltr' ),
			array( 'su', 'Sundanese', 'Basa Sunda', 'su', 'su', '', 'ltr' ),
			array( 'sw', 'Swahili', 'Kiswahili', 'sw', 'sw', '', 'ltr' ),
			array( 'tg', 'Tajik', 'Тоҷикӣ', 'tg', 'tg', '', 'ltr' ),
			array( 'tt', 'Tatar', 'Татарча', 'tt', 'tt', '', 'ltr' ),
			array( 'ti', 'Tigrinya', 'ትግርኛ', 'ti', 'ti', '', 'ltr' ),
			array( 'ts', 'Tsonga', 'Xitsonga', 'ts', 'ts', '', 'ltr' ),
			array( 'tk', 'Turkmen', 'Türkmençe', 'tk', 'tk', '', 'ltr' ),
			array( 'ak', 'Twi', 'Twi', 'ak', 'ak', '', 'ltr' ),
			array( 'ug', 'Uyghur', 'ئۇيغۇرچە', 'ug', 'ug', '', 'rtl' ),
			array( 'uz', 'Uzbek', 'Oʻzbekcha', 'uz', 'uz', '', 'ltr' ),
			array( 'cy', 'Welsh', 'Cymraeg', 'cy', 'cy', '', 'ltr' ),
			array( 'xh', 'Xhosa', 'isiXhosa', 'xh', 'xh', '', 'ltr' ),
			array( 'yi', 'Yiddish', 'ייִדיש', 'yi', 'yi', '', 'rtl' ),
			array( 'yo', 'Yoruba', 'Yorùbá', 'yo', 'yo', '', 'ltr' ),
			array( 'zu', 'Zulu', 'isiZulu', 'zu', 'zu', '', 'ltr' ),
		);
		$out  = array();
		foreach ( $rows as [ $code, $name, $native, $prefix, $google, $chrome, $dir ] ) {
			$out[ $code ] = array(
				'name'   => $name,
				'native' => $native,
				'prefix' => $prefix,
				'google' => $google,
				'chrome' => $chrome,
				'dir'    => $dir,
			);
		}
		return (array) apply_filters( 'lango7_language_catalog', $out );
	}

	/**
	 * Languages that can be chosen as source or target. Free translates with
	 * Chrome's built-in Translator, so only Chrome's languages; Pro's engines
	 * widen this through the filter.
	 *
	 * @return array<string, array{name: string, native: string, prefix: string, google: string, chrome: string, dir: string}>
	 */
	public static function available(): array {
		$all    = self::catalog();
		$chrome = array_filter( $all, static fn( array $l ): bool => '' !== $l['chrome'] );
		return (array) apply_filters( 'lango7_available_languages', $chrome, $all );
	}

	/**
	 * Chrome Translator code for a language, '' when Chrome cannot translate it.
	 */
	public static function chromeCode( string $code ): string {
		return self::catalog()[ $code ]['chrome'] ?? '';
	}

	/**
	 * Best catalogue code for a WordPress locale (en_US → en, zh_TW → zh-Hant).
	 */
	public static function fromLocale( string $locale ): string {
		$map = array(
			'zh_CN' => 'zh-Hans',
			'zh_SG' => 'zh-Hans',
			'zh_TW' => 'zh-Hant',
			'zh_HK' => 'zh-Hant',
			'pt_BR' => 'pt-BR',
			'pt_PT' => 'pt-PT',
			'en_GB' => 'en-GB',
			'en_AU' => 'en-AU',
			'es_MX' => 'es-MX',
			'fr_CA' => 'fr-CA',
			'nb_NO' => 'nb',
			'tl'    => 'fil',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		$base = strtolower( strtok( $locale, '_' ) ?: 'en' );
		return isset( self::catalog()[ $base ] ) ? $base : 'en';
	}

	/**
	 * @return Language[]
	 */
	public function enabled(): array {
		if ( null === $this->enabled ) {
			$this->enabled = array();
			foreach ( (array) get_option( self::OPTION, array() ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['code'] ) && $row['code'] !== $this->sourceCode() ) {
					$this->enabled[] = Language::fromArray( $row );
				}
			}
		}
		return $this->enabled;
	}

	/**
	 * Whether visitors and search engines get this language: false for a
	 * language that is hidden (e.g. a Pro draft) or set to noindex.
	 */
	public static function isPublic( Language $lang ): bool {
		return ! $lang->noindex && self::isLive( $lang );
	}

	/**
	 * Whether a language is open to visitors. Pro returns false for a draft
	 * language, which only the people who edit it can see.
	 */
	public static function isLive( Language $lang ): bool {
		/**
		 * Whether a language is open to visitors (false: a draft).
		 *
		 * @param bool     $live
		 * @param Language $lang
		 */
		return (bool) apply_filters( 'lango7_language_live', true, $lang );
	}

	/**
	 * Languages to offer the current visitor: live ones, plus drafts the
	 * current user can edit.
	 *
	 * @return Language[]
	 */
	public function visible(): array {
		return array_values(
			array_filter(
				$this->enabled(),
				static fn( Language $l ): bool => self::isLive( $l ) || current_user_can( Plugin::LANGUAGE_CAPABILITY, $l->code )
			)
		);
	}

	public function byPrefix( string $prefix ): ?Language {
		foreach ( $this->enabled() as $lang ) {
			if ( $lang->prefix === $prefix ) {
				return $lang;
			}
		}
		return null;
	}

	public function byCode( string $code ): ?Language {
		foreach ( $this->enabled() as $lang ) {
			if ( $lang->code === $code ) {
				return $lang;
			}
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	public function prefixes(): array {
		return array_map( static fn( Language $l ): string => $l->prefix, $this->enabled() );
	}

	public function sourceCode(): string {
		return (string) $this->settings->get( 'source_lang' );
	}

	public function source(): Language {
		return Language::fromArray(
			array(
				'code'   => $this->sourceCode(),
				'prefix' => '',
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function save( array $rows ): void {
		$removed = (array) get_option( self::REMOVED_OPTION, array() );
		foreach ( $this->enabled() as $old ) {
			$removed[ $old->prefix ] = $old->code;
		}
		foreach ( $rows as $row ) {
			unset( $removed[ (string) ( $row['prefix'] ?? '' ) ] );
		}
		update_option( self::REMOVED_OPTION, $removed, false );
		$rows = (array) apply_filters( 'lango7_language_rows', $rows, (array) get_option( self::OPTION, array() ) );
		update_option( self::OPTION, array_values( $rows ) );
		$this->enabled = null;
		if ( ! $rows ) {
			// Removing the last target opens setup again without deleting translations.
			delete_option( \Lango7\Admin\Setup::OPTION );
		}
	}

	/**
	 * Turn a language off. Its URL folder keeps redirecting to the original pages.
	 */
	public function remove( string $code ): ?Language {
		$lang = $this->byCode( $code );
		if ( null === $lang ) {
			return null;
		}
		$this->save(
			array_map(
				static fn( Language $l ): array => $l->toArray(),
				array_values( array_filter( $this->enabled(), static fn( Language $l ): bool => $l->code !== $code ) )
			)
		);
		return $lang;
	}

	/**
	 * Whether a URL folder belonged to a language that has since been turned off.
	 */
	public function isRemovedPrefix( string $prefix ): bool {
		return isset( ( (array) get_option( self::REMOVED_OPTION, array() ) )[ $prefix ] );
	}
}
