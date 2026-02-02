<?php
/**
 * Supported Translations for phpPgAdmin
 *
 * $Id: translations.php,v 1.4 2007/02/10 03:48:34 xzilla Exp $
 */


// List of language files, and encoded language name.

$appLangFiles = [
	'afrikaans' => 'Afrikaans',
	'arabic' => 'عربي',
	'catalan' => 'Català',
	'chinese-zh-CN' => '中国文简（UTF-8）',
	'chinese-zh-TW' => '繁體文简（UTF-8）',
	'czech' => 'Česky',
	'danish' => 'Danish',
	'dutch' => 'Nederlands',
	'english' => 'English',
	'french' => 'Français',
	'galician' => 'Galego',
	'german' => 'Deutsch',
	'greek' => 'Ελληνικά',
	'hebrew' => 'Hebrew',
	'hungarian' => 'Magyar',
	'italian' => 'Italiano',
	'japanese' => '日本語',
	'lithuanian' => 'Lietuvių',
	'mongol' => 'Mongolian',
	'polish' => 'Polski',
	'portuguese-br' => 'Português-Brasileiro',
	'romanian' => 'Română',
	'russian' => 'Русский',
	'russian-utf8' => 'Русский (UTF-8)',
	'slovak' => 'Slovensky',
	'swedish' => 'Svenska',
	'spanish' => 'Español',
	'turkish' => 'Türkçe',
	'ukrainian' => 'Українська'
];


// ISO639 language code to language file mapping.
// See http://www.w3.org/WAI/ER/IG/ert/iso639.htm for language codes

// If it's available 'language-country', but not general
// 'language' translation (eg. 'portuguese-br', but not 'portuguese')
// specify both 'la' => 'language-country' and 'la-co' => 'language-country'.

$availableLanguages = [
	'af' => 'afrikaans',
	'ar' => 'arabic',
	'ca' => 'catalan',
	'zh-cn' => 'chinese-zh-CN',
	'zh-tw' => 'chinese-zh-TW',
	'cs' => 'czech',
	'da' => 'danish',
	'nl' => 'dutch',
	'en' => 'english',
	'fr' => 'french',
	'gl' => 'galician',
	'de' => 'german',
	'el' => 'greek',
	'he' => 'hebrew',
	'hu' => 'hungarian',
	'it' => 'italian',
	'ja' => 'japanese',
	'lt' => 'lithuanian',
	'mn' => 'mongol',
	'pl' => 'polish',
	'pt' => 'portuguese-br',
	'pt-br' => 'portuguese-br',
	'ro' => 'romanian',
	'ru' => 'russian',
	'sk' => 'slovak',
	'sv' => 'swedish',
	'es' => 'spanish',
	'tr' => 'turkish',
	'uk' => 'ukrainian'
];

return [$appLangFiles, $availableLanguages];
