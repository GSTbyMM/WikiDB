<?php
if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
if (!defined('WikiDB')) {
	die("The WikiDB extension has not been set up correctly.<br>'"
		. basename(__FILE__) . "' should not be included directly.<br>Instead, "
		. "add the following to LocalSettings.php: "
		. "<tt><b>include_once(\"WikiDB/WikiDB.php\");</b></tt>");
}
/**
 * General-purpose utility functions for WikiDB.
 * These may be useful in other applications too.
 *
 * To avoid conflicts with other extensions (particularly my own) I either check
 * that the function hasn't already been defined prior to defining it, or else
 * I've prefixed the function name with "WikiDB_", to make the name unique.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2454 $
 */

// WikiDB_DelocaliseNumericString()
// Takes an input string, and de-localises it.
// Specifically, if the content language for the wiki defines either numerals or
// separators that are different to the standard English-language representation,
// then these are replaced with the appropriate English equivalent.
// Note that the supplied string does not need to be a valid number, and the result
// is also not guaranteed to be a valid number.  It will simply be de-localise the
// relevant characters.
	function WikiDB_DelocaliseNumericString($Value) {
		$objContentLanguage = WikiDB_GetContentLanguage();
		$Value = $objContentLanguage->parseFormattedNumber($Value);

	// The built-in function allows negative zeros.  We therefore convert these to
	// unsigned zeros, instead.  A strict comparison is required, but I'm not sure
	// why!  Without it, some of our type-guessing code incorrectly decides that
	// "0.0" is an integer.
		if ($Value === "-0")
			$Value = "0";

		return $Value;
	}

// WikiDB_IsNumeric()
// Returns true if the supplied value is a valid number in a format recognised by
// WikiDB (which is in both localised form and standard English numerals).
	function WikiDB_IsNumeric($Value) {
		$Value = WikiDB_DelocaliseNumericString($Value);
		return WikiDB_IsNormalisedNumber($Value);
	}

// WikiDB_IsNormalisedNumber()
// Returns true if the supplied value is a valid number in the normalised format,
// i.e. that it uses standard ASCII digits, has a period as the decimal separator,
// uses an ASCII hyphen as the minus symbol and does not contain any other characters
// (e.g. separators for digit grouping).
	function WikiDB_IsNormalisedNumber($Value) {
		if (preg_match('/^-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)$/', $Value))
			return true;
		else
			return false;
	}

// WikiDB_IsInteger()
// Same as IsNumeric() but does not allow non-integer values.
// Note that non-integer representations of integers (e.g. "5.0") are not valid.
// TODO: Should we allow non-integer representations of integers?
	function WikiDB_IsInteger($Value) {
		$Value = WikiDB_DelocaliseNumericString($Value);
		return WikiDB_IsNormalisedInteger($Value);
	}

// WikiDB_IsNormalisedInteger()
// Returns true if the supplied value is a valid integer in the normalised format,
// i.e. that it uses standard ASCII digits, uses an ASCII hyphen as the minus symbol
// and does not contain any other characters (e.g. separators for digit grouping).
// Note that non-integer representations of integers (e.g. "5.0") are not valid.
// TODO: Should we allow non-integer representations of integers?
	function WikiDB_IsNormalisedInteger($Value) {
		if (preg_match('/^-?[0-9]+$/', $Value))
			return true;
		else
			return false;
	}

// WikiDB_IsValidNumberFormat()
// Returns true if the supplied number is a validly-formatted number, in either the
// wiki's content language or in a normalised format, which is basically the English
// number format.
// This is a much stricter check than the WikiDB_IsNumeric(), as it ensures that the
// input does not contain digits from more than one locale and that any digit
// grouping matches the expected grouping for the locale.  Use this function for
// deciding whether untyped input should be treated as numeric and the other function
// for validating inputs when you know they should be numeric.
// TODO: Can we bypass the second check if the parameters are the same as the first?
	function WikiDB_IsValidNumberFormat($Value) {
		if (WikiDB_IsValidNonlocalisedNumber($Value))
			return true;
		elseif (WikiDB_IsValidLocalisedNumber($Value))
			return true;
		else
			return false;
	}

// WikiDB_IsValidNonlocalisedNumber()
// Wrapper to pWikiDB_IsValidNumber() which passes in the appropriate parameters for
// English number formatting.
	function WikiDB_IsValidNonlocalisedNumber($Value) {
		static $objEnglishLanguage;

	// We cache the language object, to avoid having to instantiate it multiple
	// times.
		if (!isset($objEnglishLanguage)) {
			$objEnglishLanguage = WikiDB_GetLanguageObject("en");
		}

	// The function arguments are mostly hard-coded, rather than pulled from the
	// language object, as the language object will return null for these values
	// indicating 'default' behaviour.
		return pWikiDB_IsValidNumber($Value, array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9),
									 ".", ",", $objEnglishLanguage);
	}

// WikiDB_IsValidLocalisedNumber()
// Wrapper to pWikiDB_IsValidNumber() which passes in the appropriate parameters for
// number formatting under the current locale.
	function WikiDB_IsValidLocalisedNumber($Value) {
		static $arrTidiedDigits, $DecimalSeparator, $GroupSeparator;

		$objContentLanguage = WikiDB_GetContentLanguage();

	// We cache the locale configuration, to avoid having to work it out multiple
	// times.
		if (!isset($arrTidiedDigits)) {
		// Tidy the digit transform table so that all values are mapped.
		// These are allowed to be sparse array is some digits are the same as in
		// English and may be null if all digits are the same as in English.
			$arrDigits = $objContentLanguage->digitTransformTable();
			if (!is_array($arrDigits))
				$arrDigits = array();

			$arrTidiedDigits = array();
			for ($i = 0; $i <= 9; $i++) {
				if (isset($arrDigits[$i]))
					$arrTidiedDigits = $arrDigits[$i];
				else
					$arrTidiedDigits = $i;
			}

		// Extract the separators from the separator array, using defaults for any
		// separator that is not defined.
			$arrSeparators = $objContentLanguage->separatorTransformTable();

			if (isset($arrSeparators["."]))
				$DecimalSeparator = $arrSeparators["."];
			else
				$DecimalSeparator = ".";

			if (isset($arrSeparators[","]))
				$GroupSeparator = $arrSeparators[","];
			else
				$GroupSeparator = ",";
		}

	// Call pWikiDB_IsValidNumber() to perform the validity check.
		return pWikiDB_IsValidNumber($Value, $arrTidiedDigits, $DecimalSeparator,
									 $GroupSeparator, $objContentLanguage);
	}

// pWikiDB_IsValidNumber()
// Used to decide whether a given string should be treated as a number when no format
// is explicitly specified (in order to decide how to auto-apply a type).  It is a
// stricter check than the decision about whether the supplied string is a valid
// entry for a field that is explicitly typed as numeric.
// See WikiDB_IsValidNumberFormat() for details about why this function is required
// and how it differs from WikiDB_IsNumeric().
// Returns true if all of the following are true, otherwise false:
// * The string contains just the digits specified in $arrDigits (which should
//   contain the 10 numerals that are used by the language being checked), the two
//   separator characters and the minus sign.
// * If the minus sign is present, it only appears once, at the start of the string.
// * The string contains no more than one instance of the DecimalSeparator, which is
//   not at the end of the string (may be at the beginning).  i.e. ".3" is recognised
//	 as probably a number, but "3." is not.
// * The string either contains no grouping separators, or they match the grouping
//   pattern defined in the supplied Language object.
// Note that the Language object passed in as the final parameter could be used to
// populate the other arguments, however handling them separately allows us to avoid
// some normalisation work that is unnecessary for the non-localised variant of
// this function call.
// TODO: This function was written for correctness and has not been optimised for
//		 performance at all.  There are no doubt performance improvements that could
//		 be made by being a bit more intelligent about the tests we perform and to
//		 avoid some of the string manipulations.
	function pWikiDB_IsValidNumber($Value, $arrDigits, $DecimalSeparator,
								   $GroupSeparator, $objLang)
	{
		global $wgTranslateNumerals;

	// The hyphen is always the first character, if present.
	// If it is anywhere else in the string, this is not a valid integer.
		$HyphenPos = strpos($Value, "-");
		if ($HyphenPos !== false && $HyphenPos !== 0)
			return false;

	// If the first character was a hyphen, remove it to simplify subsequent
	// processing.
		if ($HyphenPos === 0)
			$Value = substr($Value, 1);

	// Explode the string on the decimal separator.  The result should either be a
	// single element array or a 2-element array where the second element is
	// non-blank (as we don't allow for a decimal point without numbers
	// following it).
		$arrParts = explode($DecimalSeparator, $Value);
		$PartCount = count($arrParts);
		if ($PartCount > 2 || ($PartCount == 2 && $arrParts[1] === ""))
			return false;

	// Remove all valid digits and check the string has changed length.  This
	// guards against strings made up solely of separator characters (or the empty
	// string).
		$ShrunkValue = str_replace($arrDigits, "", $Value);
		if (strlen($ShrunkValue) == strlen($Value))
			return false;

	// Remove any separators.  This should result in an empty string unless there
	// are other, invalid characters present.
		$ShrunkValue = str_replace($DecimalSeparator, "", $ShrunkValue);
		$ShrunkValue = str_replace($GroupSeparator, "", $ShrunkValue);
		if ($ShrunkValue !== "")
			return false;

	// Finally, check that, if there are any group separators, the pattern follows
	// the expected digit grouping.
	// We do this by removing the thousands separator and then re-adding it via the
	// Language object, then checking the correctly-formatted string matches the
	// input string.
		if (strpos($Value, $GroupSeparator) !== false) {
			$ValueWithoutGroupings = str_replace($GroupSeparator, "", $Value);

		// You used to be able to use the commafy() function to simply add the digit
		// groupings without performing any numeric translation, but this function
		// was deprecated in MW 1.36, with no replacement.
		// Therefore, in order to achieve the same effect, we need to temporarily
		// turn off $wgTranslateNumerals and then pass the string through
		// formatNum().
			$OldTranslateNumeralsSetting = $wgTranslateNumerals;
			$wgTranslateNumerals = false;
			$FormattedValue = $objLang->formatNum($ValueWithoutGroupings);
			$wgTranslateNumerals = $OldTranslateNumeralsSetting;

			if ($Value != $FormattedValue)
				return false;
		}

	// If all tests pass, return true.
		return true;
	}

// WikiDB_StrToNumber()
// Converts a string to a number, which is returned as a float().
// Zero is returned for non-numeric strings.
	function WikiDB_StrToNumber($Value) {
		$Value = WikiDB_DelocaliseNumericString($Value);

		if (WikiDB_IsNumeric($Value))
			return floatval($Value);
		else
			return floatval(0);
	}

// WikiDB_StrToInteger()
// Converts a string to an integer value. Zero is returned for non-numeric strings.
// Non-integer values are rounded to the nearest whole number.
// Note: Despite being an integer, we actually return a float value, so that we
//		 are not restricted to the platform's integer precision.
	function WikiDB_StrToInteger($Value) {
		return round(WikiDB_StrToNumber($Value));
	}

// WikiDB_FormatFloat()
// Formats a float value as a string, with trailing zeros removed and a minimum of
// 1 digit before the decimal point, according to the current user's language.
	function WikiDB_FormatFloat($Value) {
		global $wgLang;

		$Value = sprintf("%1f", $Value);
		$Value = preg_replace("/(\.\d+)0+$/U", "$1", $Value);

		return $wgLang->formatNum($Value);
	}

// WikiDB_FormatInteger()
// Formats an integer as a string, according to the user's language.
// The input may be a string or an integer.
	function WikiDB_FormatInteger($Value) {
		global $wgLang;

	// If the input is a string value, make sure we don't end up with leading zeros.
		if (is_string($Value)) {
		// If this is a negative value, remove the minus sign and set a flag so we
		// know to add it back.
			$IsNegative = false;
			if (substr($Value, 0, 1) == "-") {
				$Value = substr($Value, 1);
				$IsNegative = true;
			}

		// Remove any leading zeros.
			$Value = ltrim($Value, "0");

		// If the result is blank or begins with a decimal point, then we have
		// removed one zero too many, so we need to add it back.
			if (IsBlank($Value) || substr($Value, 0, 1) == ".")
				$Value = "0" . $Value;

		// If this was a negative value, add the minus sign back.
			if ($IsNegative)
				$Value = "-" . $Value;
		}

	// Temporary hack.  Currently 4-digit years are being detected as integers and
	// handled via this function, which results in years like "1976" being rendered
	// as "1,976", which is clearly wrong!
	// We therefore detect numbers with 4 digits, and skip the language-specific
	// formatting which introduces the thousands-grouping.
	// TODO: This is a temporary hack to avoid a current issue.  The correct way of
	//		 solving it is to add a Year data-type, so that anything explicitly typed
	//		 as Integer still gets the thousands-grouping for 4-digit numbers, but
	//		 with a priority so that 4-digit integers are treated as Year rather than
	//		 Integer for non-typed fields.
		if (strlen($Value) == 4) {
			return $Value;
		}
		else {
			return $wgLang->formatNum($Value);
		}
	}

// WikiDB_NumberToSortableString()
// Takes a number and formats it into a string-representation suitable for sorting
// via a string sort.  If the input is not a valid number, then the function
// returns an empty string.
	function WikiDB_NumberToSortableString($Value) {
	// If value is blank, or is not a valid number, then return an empty string.
		if ($Value == "" || !WikiDB_IsNumeric($Value))
			return "";

	// Ensure we have an English-language representation of the value.
		$Value = WikiDB_DelocaliseNumericString($Value);

	// Split the number on the decimal separator, to get the two components.
		$Parts = explode(".", $Value);

	// The part before the decimal point can simply be converted to an integer to
	// remove any leading zeros and to ensure that blank values are translated to
	// a zero (so that ".1" and "0.1" are considered equal).
		$Parts[0] = intval($Parts[0]);

	// The part after the decimal point needs to be trimmed of trailing zeros,
	// but any leading zeros need to be preserved, so we need to use a string
	// manipulation rather than using intval().
	// Also, if there is no second part, we normalise this to '0', so that
	// "1" and "1.0" are considered equal.
		if (isset($Parts[1])) {
			$Parts[1] = rtrim($Parts[1], "0");
			if (IsBlank($Parts[1]))
				$Parts[1] = "0";
		}
		else {
			$Parts[1] = 0;
		}

	// Set the sign byte.  We use two values that we know will be in the
	// correct sort order, regardless of the character set.
		if ($Parts[0] < 0) {
			$Parts[0] = abs($Parts[0]);
			$Sign = "n";
		}
		else {
			$Sign = "p";
		}

	// Field size is 255. 3 bytes are required for the initial sort index,
	// the sign and the decimal point. This leaves 126 bytes of padding for
	// each part. We use a space at the start, to ensure numbers are sorted
	// _before_ strings.  We don't bother to pad the numbers after the decimal
	// point, as they are left-aligned and so the padding makes no difference.
		return sprintf(" %s%126d.%s", $Sign, $Parts[0], $Parts[1]);
	}

// WikiDB_EscapeHTML()
// This function should be used instead of htmlspecialchars(), as it provides a
// single point where we convert plain-text to safe HTML.
// The way htmlspecialchars() works varies between versions, and in particular the
// default character set changed in PHP 5.4 and again in PHP 5.6.  Before PHP 5.4
// it was ISO-8859-1, for PHP 5.4 and 5.5 it was UTF-8 and from PHP 5.6 onwards
// it is controlled via a configuration setting in php.ini.
// Therefore, for code to be safe, you need to always specify the character-set and
// this function is the recommended way to do this.  It ensures brevity and makes the
// intention clear, plus it means that we only have a single place to update if we
// need to modify how this functionality works.
// The $Flags argument is the same as for htmlspecialchars().
	function WikiDB_EscapeHTML($Input, $Flags = ENT_COMPAT) {
	// Our output encoding should always be UTF-8.
	// TODO: Prior to MW 1.18.0, the $wgOutputEncoding variable (though deprecated in
	//		 MW 1.5) still existed and could be overridden to specify a different
	//		 character set.  So long as we support MW < 1.18, we should check this
	//		 setting and use the appropriate encoding here.
		$Encoding = "UTF-8";
		return htmlspecialchars($Input, $Flags, $Encoding);
	}

// pWikiDB_ExpandVariables()
// Simple function to expand variables in a string, e.g. {{{2}}} is replaced with
// the value in element 2 of the array.  Used when we expand template arguments when
// rendering a <repeat> tag.
	function pWikiDB_ExpandVariables($Input, $Data, $Parser, $Frame) {
	// Fix to the way we handle fields which don't contain any data so that it is
	// possible for wiki editors to specify default values for blank fields.
	// Any blank fields are now removed from the data array, so when it comes to
	// template expansion it is if they don't exist, rather than being present but
	// blank, and therefore users can now specify default values that can be used for
	// missing/blank fields.
	// To stop this resulting in the field name being literally output in situations
	// where no default has been set (e.g. "{{{FieldName}}}" being rendered as
	// "{{{FieldName}}}") we replace any such instances with the necessary markup to
	// specify a blank default.  This means that "{{{FieldName}}}" will be output
	// as "".
	// TODO: Is this the best behaviour?  Should we treat these in the same way as
	//		 missing template arguments and leave it for the wiki editor to ensure
	//		 that proper defaults have been set up?
	// TODO: Should we handle missing (null) data differently from existing but blank
	//		 data?  I'm not sure we currently distinguish between the two, but
	//		 perhaps we should do?
		foreach ($Data as $Key => $Value) {
		// Replace any parameters which don't specify a default so that they instead
		// default to an empty string.
			$Key = preg_quote($Key, "/");
			$Input = preg_replace('/{{{\s*(' . $Key . ')\s*}}}/',
								  '{{{$1|}}}', $Input);

		// Remove any blank values from the data array so the MediaWiki parser treats
		// them as missing template parameters, and allows the specified default.
			if (IsBlank($Value)) {
				unset($Data[$Key]);
			}
		// Otherwise, ensure the value is a string.  The MediaWiki pre-processor
		// trips up in the expand() function if this is not a string representation.
		// For fields that have come from the wiki, this will always be the case, but
		// some fields may have come from meta-data and may therefore be non-string
		// representations (e.g. the '_Row' field is an integer).
			else {
				$Data[$Key] = strval($Value);
			}
		}

	// Work out which template class to use for this type of frame.
	// Note that Hash frame and DOM frame both existed since the Frame classes were
	// introduced (in MW 1.27).  The DOM frame is used if the PHP 'dom' extension
	// is installed, so in all my testing only this preprocessor will have been
	// used.  In MW 1.34 the DOM frame was deprecated and Hash frame is now always
	// used.  (The class was removed altogether in MW 1.35, but it was redundant
	// since MW 1.34).
	// Therefore, we need to detect the appropriate PPTemplateFrame class to use
	// based on the Frame type, and can't simply assume PPFrame_Hash in all
	// instances.
	// @back-compat MW < 1.35
		if (is_a($Frame, "PPFrame_Hash"))
			$TemplateFrameClass = "PPTemplateFrame_Hash";
		else
			$TemplateFrameClass = "PPTemplateFrame_DOM";

	// Set up a new frame which mirrors the existing one but which also has the
	// field values as arguments.
	// If we are already in a template frame, merge the field arguments with the
	// existing template arguments first.
		if ($Frame instanceof $TemplateFrameClass) {
		// TODO: Is there a reason we're not using getNumberedArgs()/getNamedArgs()
		//		 here?
			$NumberedArgs = $Frame->numberedArgs;
			$NamedArgs = array_merge($Frame->namedArgs, $Data);
		}
		else {
			$NumberedArgs = array();
			$NamedArgs = $Data;
		}

	// Create the new frame.
	// TODO: Is there a reason we're not using $Frame->newChild(), here?
		$NewFrame = new $TemplateFrameClass($Frame->preprocessor, $Frame,
											$NumberedArgs, $NamedArgs,
											$Frame->title);

	// Perform a recursive parse on the input, using our newly created frame.
		return $Parser->replaceVariables($Input, $NewFrame);
	}

// WikiDB_Parse()
// Wrapper function to simplify text parsing.
// If no existing Parser is passed in, it creates a fresh Parser instance and uses
// that.  You should not pass in $Frame unless you also supply $Parser.
	function WikiDB_Parse($Input, $Parser = null, $Frame = null) {
		if ($Parser === null)
			$Parser = WikiDB_GetParser();

		return $Parser->recursiveTagParse($Input, $Frame);
	}

// WikiDB_GetParser()
// Returns a fresh Parser object, which can be used for parsing wikitext.
// This should always be used in place of accessing $wgParser directly, as the
// global variable has been deprecated as of MW 1.32 and will eventually be removed
// altogether.
	function WikiDB_GetParser() {
	// If the getParser() function exists on the MediaWikiServices class
	// then use this to get the Parser object.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.32.
		if (method_exists("MediaWiki\\MediaWikiServices", "getParser")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$objParser = $MWServices->getParser();
		}
	// On earlier versions, $wgParser is available, so we simply use that.
	// @back-compat MW < 1.32
		else {
			global $wgParser;
			$objParser = $wgParser;
		}

	// The getFreshParser() was added in MW 1.24, so we need to check whether it
	// exists.
	// @back-compat MW < 1.24
		if (method_exists($objParser, "getFreshParser"))
			return $objParser->getFreshParser();
		else
			return $objParser;
	}

// WikiDB_GetTitleFromParser()
// Wrapper function to handle the fact that the way you retrieve a Title object
// from a Parser object has changed over time.  In addition, it looks like it will
// be changing further in the future, so there may be further development of this
// function required.
	function WikiDB_GetTitleFromParser($objParser) {
	// Older versions of MediaWiki use Parser::getTitle().  As of MW 1.37, this
	// function is deprecated and Parser::getPage() should be used instead.
	// Currently, this function still returns a Title object and so is fully
	// compatible with the previous implementation (just the name has changed).
	// However, this may well change in the future.  I will need to keep an eye on
	// this!
	// @back-compat MW < 1.37
		if (method_exists($objParser, "getPage")) {
			return $objParser->getPage();
		}
		else {
			return $objParser->getTitle();
		}
	}

// WikiDB_GetNeutralParserOptions()
// Returns a ParserOptions object, configured to disable any settings that may cause
// content to vary between users (as much as is possible).  Where this is not
// possible, the default settings for anonymous users are used.
// Most parsing that we need to do manually is done once and then cached, and we do
// not want any user settings to affect the parse, therefore we need the options to
// be set as neutrally as possible.  In practice, most of the things we parse-out
// won't be affected by configuration options as they just take the raw wiki text,
// but there are some places (e.g. pages in Table namespaces) where configuration
// settings could maybe make a difference.
// In addition, we disable any non-essential options that may have performance
// implications, in order to speed-up the parse.
	function WikiDB_GetNeutralParserOptions() {
	///////////////////////////
	// INSTANTIATION

	// Backwards-compatibility - the recommended way to get a 'canonical' parser
	// object has evolved.  Note that these are all equivalent, and the oldest
	// method is still a valid way of constructing a ParserOptions object.  However,
	// we want to follow best-practice where possible, so the recommended method is
	// used, where available.
	// From MediaWiki 1.32, onwards, the newCanonical() function recognises
	// 'canonical' as meaning a 'default, non-customised' set of ParserOptions.
	// In practice, this currently is the same as calling newFromAnon(), but that
	// may change as things evolve, therefore it is best to use this recommended
	// method.
	// We need an explicit version check, as the function was added in MW 1.30, but
	// the 'canonical' argument is only recognised from MW 1.32, onwards.
		if (version_compare(MW_VERSION, "1.32", ">=")) {
			$objParserOptions = ParserOptions::newCanonical('canonical');
		}
	// From MW 1.27, the newFromAnon() function can be used to get an anonymous
	// ParserOptions object.
	// @back-compat MW < 1.32
		elseif (method_exists("ParserOptions", "newFromAnon")) {
			$objParserOptions = ParserOptions::newFromAnon();
		}
	// On older versions, a User object needs to be explicitly set (if it is omitted,
	// the global $wgUser object is used, which is not what we want).  We therefore
	// create a new User object, to represent the anonymous user.
	// This is how newFromAnon() works, so this is functionally identical to how
	// the more modern code works.
	// @back-compat MW < 1.27
		else {
			$objParserOptions = new ParserOptions(new User);
		}

	///////////////////////////
	// CONFIGURATION
	// Not sure how necessary setting these options is - it's only worthwhile
	// it it helps speed up the parse.

	// setUseDynamicDates() was removed in MW 1.21, so only call it if present.
	// Details: https://phabricator.wikimedia.org/T31472
	// TODO: Is there a replacement property we should set, or are dates just
	//		 always (or never) rewritten?
	// @back-compat MW < 1.21
		if (is_callable($objParserOptions, "setUseDynamicDates"))
			$objParserOptions->setUseDynamicDates(false);

		$objParserOptions->setInterwikiMagic(false);

	// setNumberHeadings() was removed in MW 1.38, so only call it if present.
	// Details: https://phabricator.wikimedia.org/T284921
	// @back-compat MW < 1.38
		if (is_callable($objParserOptions, "setNumberHeadings"))
			$objParserOptions->setNumberHeadings(false);

		return $objParserOptions;
	}

// WikiDB_DisableCache()
// Prevents the results of the current parse from being cached.  This makes most
// sense in the context of parsing a full page, but may make sense in other parsing
// contexts, too.
// Primarily provided for backwards-compatibility reasons, but may still be useful
// when older MW versions no longer need to be supported, for clarity and brevity.
	function WikiDB_DisableCache($objParser) {
	// Prior to MW 1.28, you simply called disableCache() on the Parser instance in
	// order to disable the cache.  In MW 1.28 this was deprecated and it was
	// ultimately removed in MW 1.35.
	// Note that we need a version check here as this can't be feature-detected.  All
	// relevant functions have existed since at least MW 1.17 and we don't want to
	// call disableCache() after deprecation, as it will emit notices.
	// TODO: Although the deprecation was made in MW 1.28, it is possible that the
	//		 new technique worked correctly in older MW versions.  It may therefore
	//		 be possible to turn this into a feature-detection check after all.
	// @back-compat MW < 1.28
		if (version_compare(MW_VERSION, "1.28", "<")) {
			$objParser->disableCache();
		}
	// In more recent MediaWiki versions, you need to first retrieve the ParserOutput
	// object from the Parser and then set the cache expiry to zero seconds.
	// TODO: Although the deprecation was made in MW 1.28, it is possible that the
	//		 new technique worked correctly in all supported MW versions (i.e. back
	//		 to MW 1.16).  If that is the case, we can remove the
	//		 backwards-compatibility branch altogether.
		else {
			$objParserOutput = $objParser->getOutput();
			$objParserOutput->updateCacheExpiry(0);
		}
	}

// WikiDB_GetContentLanguage()
// Provides a method of retrieving the wiki's content Language object that works
// across all supported MediaWiki versions.
// The global $wgContLang object has been deprecated as of MW 1.32, so any code that
// needs to access the content Language object now needs to call this function rather
// than accessing $wgContLang, directly.
	function WikiDB_GetContentLanguage() {
	// If the getContentLanguage() function exists on the MediaWikiServices class
	// then use this to get the content Language object.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.32.
		if (method_exists("MediaWiki\\MediaWikiServices", "getContentLanguage")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			return $MWServices->getContentLanguage();
		}
	// On earlier versions, $wgContLang is available, so we simply return that.
	// @back-compat MW < 1.32
		else {
			global $wgContLang;
			return $wgContLang;
		}
	}

// WikiDB_GetLanguageObject()
// Provides a method of retrieving a Language object representing the specified
// language, in a manner that works across all supported MediaWiki versions.
// Language::factory() was deprecated in MediaWiki 1.35 and will ultimately be
// removed.  As of MW 1.35, we therefore need to use the new LanguageFactory class,
// instead.
// @back-compat MW < 1.35
	function WikiDB_GetLanguageObject($LanguageCode) {
	// If the getLanguageFactory() function exists on the MediaWikiServices class
	// then use this to get the LanguageFactory object that can be used to retrieve
	// the requested language.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.35.
		if (method_exists("MediaWiki\\MediaWikiServices", "getLanguageFactory")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$objFactory = $MWServices->getLanguageFactory();
			return $objFactory->getLanguage($LanguageCode);
		}
	// On earlier versions, use the Language::factory() function, which is much
	// simpler.
	// @back-compat MW < 1.35
		else {
			return Language::factory($LanguageCode);
		}
	}

// WikiDB_IsValidTable()
// Wrapper for WikiDB_Table::IsValidTable().  That function can only be called once
// you've instantiated a table object, but often we need to check whether the raw
// title we have received refers to a valid table without having to create a table
// object for it.  This function allows us to do that.
// Arguments are the same as for the WikiDB_Table constructor, so are quite flexible.
// Note that previously we allowed the class method to be called both statically
// and non-statically, but this throws E_STRICT warnings, due to a stupid design
// choice by the PHP authors.  Oh well.  We write more complicated code to keep the
// error logs happy. :-(
	function WikiDB_IsValidTable($Title, $DefaultNamespace = null) {
		$Table = new WikiDB_Table($Title, $DefaultNamespace);
		return $Table->IsValidTable();
	}

// WikiDB_MakeTitle()
// $Title may either be a title object or a string representing the name of a
// page.  If $Title is a string, but doesn't include a namespace, then
// $DefaultNamespace is used if specified.  If no default is specified then
// WikiDB::GetDefaultTableNamespace() is used to fill in a sensible default.
// Returns a Title object, or null if the title could not be parsed because it
// is invalid.
	function WikiDB_MakeTitle($Title, $DefaultNamespace = null) {
	// If $Title is already a Title object, just return it.
	// TODO: Currently we assume that if it is an object, it is a Title object.
	//		 Is this sensible?
		if (is_object($Title)) {
			return $Title;
		}
	// Otherwise, it is a text string representing a page title.
	// In this case we create a new title object from that string, using the
	// default table namespace if necessary.
		else {
		// If no default namespace is specified, use the standard default.
			if ($DefaultNamespace === null)
				$DefaultNamespace = WikiDB::GetDefaultTableNamespace();

		// TODO: Should this use Title::newFromDBKey() instead?  If $Title contains
		//		 text it *should* always be a DB key, but it seems that the
		//		 transformations involved in newFromText() won't affect strings that
		//		 are already transformed, therefore it is safe (if marginally slower)
		//		 to pass it through there anyway - and that avoids problems if we
		//		 get passed in the actual title, rather than the DB key, for any
		//		 reason.
			return Title::newFromText($Title, $DefaultNamespace);
		}
	}

// WikiDB_MakeLink()
// This function should be used by all internal code when generating links, to ensure
// it is fully portable across MW versions.
// The method for rendering a link has changed numerous times:
// * In 1.7 and below, Linker::makeLinkObj() needed to be called on an instantiated
//   object.  This was normally the user's Skin class, which is a child of Linker.
//	 (We don't handle this case, as we no longer support any version for which it is
//	 applicable).
// * In 1.14, link() was added, which became the preferred method.  This has a
//   different calling signature (which has remained broadly unchanged in later
//	 incarnations).
// * In 1.18, the link() function was made static, so it should be called directly
//	 on the Linker class, rather than on an instantiated object.
// * In 1.27 the makeObjLink() function was removed altogether.
// * In 1.28, Linker::link() was deprecated in favour of the new LinkRenderer
//   class.
	function WikiDB_MakeLink($objTitle, $LinkText = null, $arrQueryParams = array())
	{
	// If the getLinkRenderer() function exists on the MediaWikiServices class
	// then use this to get the link renderer object, which we can use to make the
	// link.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.28.
		if (method_exists("MediaWiki\\MediaWikiServices", "getLinkRenderer")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$LinkRenderer = $MWServices->getLinkRenderer();
			$Callable = array($LinkRenderer, "makeLink");
		}
	// Otherwise, we are using an older version of MediaWiki.
	// @back-compat MW < 1.28
		else {
		// $wgUser is already deprecated and will ultimately be removed so, to avoid
		// confusion, we put the global declaration in this backwards-compatibility
		// code branch, not at the top of the function (as best-practice would
		// normally dictate).
			global $wgUser;

		// User::getSkin() was removed in MW 1.27 (deprecated since 1.18).
		// However the above code, using getLinkRenderer() was only added in MW 1.28.
		// Therefore, if the User class doesn't have a getSkin() function, then we
		// are on MW 1.27, in which case we use the RequestContext class to get the
		// current skin (this is what getSkin() did on MW >= 1.18, < 1.27).
			if (!method_exists($wgUser, "getSkin"))
				$Skin = RequestContext::getMain()->getSkin();
			else
				$Skin = $wgUser->getSkin();

		// If the $Skin object has a 'link' function, we call it.  This is required
		// for MW versions between the function being implemented and before it being
		// rewritten to work statically (where we need to call it on an object
		// instance).
		// @back-compat MW < 1.18
			if (method_exists($Skin, "link")) {
				$Callable = array($Skin, "link");
			}
		// If none of the above apply, we are on a MW version between the point where
		// Linker::link() became a static function and where the LinkRenderer class
		// was introduced (in MW 1.28).  In this case, the static function is the one
		// to use.
		// @back-compat MW < 1.28
			else {
				$Callable = array("Linker", "link");
			}
		}

	// Set up the arguments that will be passed to the selected function.
	// All variants take the same arguments in the same order.
		$arrArgs = array($objTitle, $LinkText, array(), $arrQueryParams);

		return call_user_func_array($Callable, $arrArgs);
	}

// GetCanonicalNamespaceName()
// Returns the canonical name of the specified namespace.  I've written a wrapper
// function, as this isn't as simple as I'd like!
	function WikiDB_GetCanonicalNamespaceName($NS) {
	// Get the canonical name for the namespace using the getCanonicalName() function
	// from the NamespaceInfo/MWNamespace class (depending on MW version).
	// This will return the canonical name for any namespace that exists, or an empty
	// string if the namespace is not recognised.  It also returns a blank value for
	// the main namespace, which doesn't have a prefix.
	// The MWNamespace class was deprecated in MW 1.34, in favour of NamespaceInfo.
	// Use whichever is appropriate.
	// @back-compat MW < 1.34
		if (class_exists("NamespaceInfo")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$NSInfo = $MWServices->getNamespaceInfo();
			$NSName = $NSInfo->getCanonicalName($NS);
		}
		else {
			$NSName = MWNamespace::getCanonicalName($NS);
		}

	// Handle situations where the name comes back blank.
		if ($NSName == "") {
		// If this is Namespace 0, then it is the main namespace.  This doesn't have
		// an actual name, but we still need to report it.  We therefore use 'main'
		// for this.
			if ($NS == 0) {
				$NSName = WikiDB_Msg('blanknamespace');
			}
		// Otherwise, it is an undefined namespace, which is reported as such.
			else {
				$NSName = WikiDB_Msg('undefined');
			}
		}

		return $NSName;
	}

// WikiDB_GetSubjectPage()
// Takes a Title object and returns the equivalent Title object that represents the
// title's subject page.  Titles that are already subject pages will be returned
// directly.
// This function is a wrapper to ensure the functionality works correctly across all
// supported MediaWiki versions.  Title::getSubjectPage() was deprecated in
// MediaWiki 1.34 and will ultimately be removed.  For 1.34 and above we therefore
// need to use the NamespaceInfo class, instead.
// @back-compat MW < 1.34
	function WikiDB_GetSubjectPage($objTitle) {
	// If the getNamespaceInfo() function exists on the MediaWikiServices class
	// then use this to get the NamespaceInfo object, which is the new location for
	// the getSubjectPage() function.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.34.
		if (method_exists("MediaWiki\\MediaWikiServices", "getNamespaceInfo")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$objNamespaceInfo = $MWServices->getNamespaceInfo();
			$objTitleValue = $objNamespaceInfo->getSubjectPage($objTitle);

		// The returned object is a TitleValue object, not a Title object, which the
		// calling code needs.  We therefore convert it to a Title object.
		// TODO: Once we have dropped support for older MediaWiki versions we may be
		//		 able to rewrite the calling code to operate on TitleValue objects,
		//		 instead.  This would be more future proof and match best-practices
		//		 for working with Titles, but is not possible until our minimum
		//		 version is raised such that TitleValue is always available.
			return Title::newFromLinkTarget($objTitleValue);
		}
		else {
			return $objTitle->getSubjectPage();
		}
	}

// WikiDB_GetLocalSpecialPageName()
// Cross-version wrapper for the MediaWiki code which gets the local name for a
// given special page.  This is the name that is displayed in the interface and also
// the name that is used for creating links to the page.
	function WikiDB_GetLocalSpecialPageName($CanonicalPageName) {
	// If the getSpecialPageFactory() function exists on the MediaWikiServices class
	// then use this to get the special page factory object, which we can use to
	// perform the conversion.
	// We need to check for the method as the class was introduced in MW 1.27, but
	// the method was only added in MW 1.32.
		if (method_exists("MediaWiki\\MediaWikiServices", "getSpecialPageFactory")) {
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));
			$SpecialPageFactory = $MWServices->getSpecialPageFactory();
			return $SpecialPageFactory->getLocalNameFor($CanonicalPageName);
		}
	// Otherwise, if SpecialPageFactory::getLocalNameFor() exists, then call this
	// method statically, as this was the recommended method for versions of
	// MediaWiki from 1.18 to 1.31.
	// @back-compat MW < 1.32
		elseif (is_callable(array('SpecialPageFactory', 'getLocalNameFor'))) {
			return SpecialPageFactory::getLocalNameFor($CanonicalPageName);
		}
	// Otherwise, use the old method, which gets the name via the Language class.
	// This was deprecated in MW 1.24 and removed altogether in MW 1.28.
	// @back-compat MW < 1.18
		else {
		// Note that it is OK to access $wgContLang directly here, as this code will
		// only run on older versions of MediaWiki, from before the variable was
		// deprecated.
			global $wgContLang;
			return $wgContLang->specialPage($CanonicalPageName);
		}
	}

// WikiDB_GetAction()
// Returns the current page action.  We wrap it into a function because the method
// for dealing with this has changed between MW versions.  This ensures we can
// easily maintain compatibility.
	function WikiDB_GetAction() {
		global $action, $mediaWiki;

	// The $mediaWiki object has existed since very early versions (was present in
	// MW 1.6, for example).  However the getAction() method wasn't added until
	// MW 1.18.  Therefore we need to check whether this function exists before
	// calling it, to ensure compatibility with older versions.
	// If it doesn't exist, we use the global $action variable instead, which is
	// the old way of getting this same information.
	// @back-compat MW < 1.18
		if (is_callable(array($mediaWiki, "getAction")))
			return $mediaWiki->getAction();
		else
			return $action;
	}

// WikiDB_NewFromRedirect()
// Title::newFromRedirect was deprecated in MediaWiki 1.21, and removed altogether
// in MW 1.28, in favour of ContentHandler::makeContent().
// This function provides a wrapper that works in all supported versions of
// MediaWiki.
	function WikiDB_NewFromRedirect($PageText) {
	// @back-compat MW < 1.21
		if (class_exists('ContentHandler')) {
			$objContentHandler = ContentHandler::makeContent($PageText, null,
															 CONTENT_MODEL_WIKITEXT);
			return $objContentHandler->getRedirectTarget();
		}
		else {
			return Title::newFromRedirect($PageText);
		}
	}

// WikiDB_AddWikiText()
// Replacement for $wgOut->addWikiText().  This function was deprecated in MW 1.32
// and will ultimately be removed.
// It was replaced by two functions, one for adding interface text
// (addWikiTextAsInterface()) and one for adding content (addWikiTextAsContent())
// which correspond to the $interface flag that was passed into addWikiText().
// To upgrade, code should be updated to use one or other of these functions
// depending on whether the $interface flag is set to true or false (it defaults to
// true).
// This function provides a backwards-compatibility shim, which takes the same
// arguments as addWikiText(), plus an additional argument which is a reference to
// the OutputPage object (as this isn't necessarily the global $wgOut variable).
// If we are on MediaWiki >= 1.32 then it uses the new functions (based on the
// $Interface flag) otherwise it uses the original addWikiText() function.
// Note that WikiDB doesn't actually use the $LineStart or $Interface functions, so
// this function could be much simplified for our current requirements.  However, I
// have kept it as a full implementation just in case we find we need the extra
// arguments in the future, and also as a useful example of how to shim this
// properly, in case it is needed in other projects.
// I decided against making $objOutput an optional parameter (pulling it from the
// global $wgOut variable if omitted) in order to make it clearer what is happening
// in any calling code, as well as making the code more future-proof against the
// likelihood of the global being deprecated.
// @back-compat MW < 1.32
	function WikiDB_AddWikiText($Text, $objOutput, $LineStart = true,
								$Interface = true)
	{
		if (method_exists($objOutput, "addWikiTextAsInterface")) {
			if ($Interface)
				$objOutput->addWikiTextAsInterface($Text, $LineStart);
			else
				$objOutput->addWikiTextAsContent($Text, $LineStart);
		}
		else {
			$objOutput->addWikiText($Text, $LineStart, $Interface);
		}
	}

// WikiDB_Msg()
// Returns the text of the specified system message, using any additional arguments
// for variable replacement.
// Functionally equivalent to wfMsg(), which is the original way of handling this
// in MediaWiki.  However, this was deprecated in favour of wfMessage() and the new
// Message class in MW 1.17 and removed altogether in MW 1.27.
// This wrapper function calls wfMessage() if available, otherwise wfMsg(), and
// in both cases returns the expanded string (not a Message object).  As well as
// ensuring compatibility across MediaWiki versions, using this function simplifies
// our code as it doesn't need to explicitly call text() to get the output string.
// Note that the only difference between this and wfMsg() is that it benefits from
// some bug fixes that were applied to the wfMessage() implementation, namely that
// variable substitution now happens before template expansion, so constructs such
// as {{PLURAL:$1|egg|eggs}} now work as expected.
	function WikiDB_Msg($Key) {
		$Args = func_get_args();

	// @back-compat MW < 1.17
		if (function_exists("wfMessage")) {
			$objMessage = call_user_func_array("wfMessage", $Args);
			return $objMessage->text();
		}
		else {
			return call_user_func_array("wfMsg", $Args);
		}
	}

// WikiDB_MsgExpanded()
// Same as WikiDB_Msg(), except it additionally parses the supplied key as wikitext
// and returns the resulting HTML.
// Equivalent to wfMsgExt($Text, array('parse')) and is required in order to retain
// support for earlier MW versions, now that wfMsgExt() has been removed.
// The function requires that you pass in an OutputPage object as the final argument
// to give it the context for rendering.  (Any variables to be expanded in the source
// string must be included as separate arguments between the $Key and $objOutputPage
// arguments, e.g. WikiDB_MsgExpanded('message', $Arg1, $Arg2, $objOutputPage)
// As the only place that calls this function has a context available, requiring this
// argument is not an onerous burden, but if we find it becomes problematic
// then the correct solution is to update the function to use the Message class, and
// therefore to not rely on an OutputPage object at all.
// TODO: Should be updated to use the Message class if it exists, rather than
//		 this custom implementation.  As it is used so lightly, this is a minor
//		 point of correctness rather than something that is actually causing an
//		 issue.
// @back-compat MW < 1.17
	function WikiDB_MsgExpanded($Key, $objOutputPage) {
	// Get the argument list and remove the OutputPage object from the end.
		$Args = func_get_args();
		$objOutputPage = array_pop($Args);

	// Pass the remaining arguments to WikiDB_Msg() to expand the key (first
	// argument) using any specified variable substitutions (remaining arguments).
		$Output = call_user_func_array("WikiDB_Msg", $Args);

	// As of MediaWiki 1.32, OutputPage::parse() is deprecated, and
	// parseAsInterface() should be used instead.
	// @back-compat MW < 1.32
		if (method_exists($objOutputPage, "parseAsInterface"))
			return $objOutputPage->parseAsInterface($Output, true);
		else
			return $objOutputPage->parse($Output, true, true);
	}

// WikiDB_GetCurrentTextForTitle()
// Returns the current article text for the supplied Title object.  This is the
// raw article text, not tailored to the current user.
// Returns false if the argument is invalid or refers to a Title that doesn't
// exist (or has no revisions).
	function WikiDB_GetCurrentTextForTitle($Title) {
	// If the RevisionRecord class exists, then we use the modern method for
	// retrieving the text.  Under the new method, each revision can have multiple
	// 'slots' - the main slot contains the text but there may also be slots for
	// additional content, e.g. meta-data, alternative representations, etc.
	// In addition, the method for retrieving a revision has also changed, to go
	// via MediaWikiServices.
	// This branch handles the modern method in its entirety, with the other branch
	// providing appropriate backwards-compatibility code.
	// Note that the RevisionRecord class was introduced in MW 1.31, but under
	// a different namespace (MediaWiki\Storage).  In MW 1.32 it was moved to
	// the current location (MediaWiki\Revision).  Because the way we retrieve and
	// interact with the class is the same, we allow for both locations, in order to
	// ensure compatibility with both revisions.
	// @back-compat MW = 1.31
		if (class_exists("MediaWiki\\Revision\\RevisionRecord")
			|| class_exists("MediaWiki\\Storage\\RevisionRecord"))
		{
		// Get a MediaWikiServices instance.
		// To ensure the code remains syntax-compatible with all supported versions
		// of PHP, we can't use a literal namespace.  Nor can we use
		// $ClassName::Method().
		// We therefore use a string variable and call_user_func().
		// @back-compat PHP < 5.3 (MW < 1.20)
			$ClassName = "MediaWiki\\MediaWikiServices";
			$MWServices = call_user_func(array($ClassName, "getInstance"));

		// Get the appropriate RevisionLookup object.
			$objRevisionLookup = $MWServices->getRevisionLookup();

		// Get the latest revision for this title.
			$objRevisionRecord = $objRevisionLookup->getRevisionByTitle($Title);

		// If we did not receive a valid RevisionRecord object, then return false to
		// indicate an error.
			if (!$objRevisionRecord)
				return false;

		// Get the string representing the main slot, from the SlotRecord::MAIN
		// constant.
		// Note that prior to MW 1.32, this constant was not defined and was instead
		// hard-coded everywhere, so we need a version-check to ensure MW 1.31 is
		// properly supported.
		// @back-compat MW < 1.32
			if (version_compare(MW_VERSION, "1.32", ">=")) {
			// To ensure the code remains syntax-compatible with all supported
			// versions of PHP, we can't use a literal namespace.  Nor can we
			// directly refer to $ClassName::CONSTANT.
			// We therefore need to use eval() in order to access the class constant.
			// @back-compat PHP < 5.3 (MW < 1.20)
				$MainSlot = eval("return MediaWiki\\Revision\\SlotRecord::MAIN;");
			}
			else {
				$MainSlot = "main";
			}

		// Get the content object for the main slot.
			$objContent = $objRevisionRecord->getContent($MainSlot,
														 WIKIDB_RawRevisionText);
			return WikiDB_GetContentText($objContent);
		}
	// For older MediaWiki versions, we will operate on the Revision class.
	// @back-compat MW < 1.31
		else {
		// Get the current revision for this Title.
			$Revision = Revision::newFromTitle($Title);

		// If we did not receive a valid Revision object, then return false to
		// indicate an error.
			if (!$Revision)
				return false;

		// If we have a valid Revision, get the article text from it.
		// Revision::getText() was deprecated in MW 1.21 and removed altogether in
		// MW 1.29.  We use this function if it is available, but on later MW
		// versions there is a new ContentHandler class wrapper around the revision
		// content, so some additional steps are needed.
		// @back-compat MW < 1.21
			if (method_exists($Revision, "getText")) {
				return $Revision->getText();
			}
			else {
			// We always retrieve the raw text for the given revision, without any
			// permission checking, as our use-case should treat the text in a
			// user-agnostic manner.
				$objContent = $Revision->getContent(WIKIDB_RawRevisionText);
				return WikiDB_GetContentText($objContent);
			}
		}
	}

// WikiDB_GetContentText()
// Wrapper function to get the text content from a Content object.
	function WikiDB_GetContentText($objContent) {
	// Check that the getText() function exists on the supplied object.
	// This guards against MW < 1.33, where this function was not implemented, but
	// also against the chance that the content object is not TextContent.
		if (method_exists($objContent, "getText")) {
			return $objContent->getText();
		}
	// The new ContentHandler implementation was added in MW 1.21, but the getText()
	// function was only added to the TextContent class in MW 1.33, therefore we
	// need to check for the existence of this function before calling it.
	// @back-compat MW < 1.33
		elseif (method_exists("ContentHandler", "getContentText")) {
			return ContentHandler::getContentText($objContent);
		}
	// Fall-back, in case the function is passed an object that is not a TextContent
	// item, i.e. where there is no text representation.  For MW versions that still
	// provide ContentHandler::getContentText(), that function will handle it for us,
	// but this case will guard against that situation once the deprecated function
	// has been removed.
	// I don't know whether this could ever happen, or whether we'll always receive
	// TextContent objects, but we guard against it just in case.
		else {
			return "";
		}
	}

// WikiDB_GetArticleText()
// Article::getContent() was deprecated in MW 1.21 and removed altogether in MW 1.29.
// We therefore need to use a wrapper function so that we can perform this action on
// all supported MediaWiki versions.
// This always works on the raw text for the given article, without any permission
// checking, as it is designed for handling data reparsing which is user-agnostic.
	function WikiDB_GetArticleText($Article) {
	// @back-compat MW < 1.21
		if (method_exists($Article, "getPage")) {
			$objWikiPage = $Article->getPage();
			$objContent = $objWikiPage->getContent(WIKIDB_RawRevisionText);
			return WikiDB_GetContentText($objContent);
		}
		else {
			return $Article->getContent();
		}
	}

// WikiDB_ArticleExists()
// Wrapper function which returns true if the specified article exists, false
// otherwise.
// It uses $Article->getPage()->exists() on versions of MediaWiki that support it
// (MW 1.19 and above) and $Article->exists() on older versions.  The exists()
// function was deprecated from the Article class in MW 1.35 and removed altogether
// in MW 1.36, but the recommended alternative existed for a long time prior to
// that (it was added before getPage() exposed the internal WikiPage object, which
// means it is actually the addition of getPage() that sets our minimum version
// requirements).
// @back-compat MW < 1.19
	function WikiDB_ArticleExists($Article) {
		if (method_exists($Article, "getPage")) {
			$WikiPage = $Article->getPage();
			return $WikiPage->exists();
		}
		else {
			return $Article->exists();
		}
	}


// WikiDB_GetPaginationLinks()
// Cross-version wrapper for the MediaWiki prev/next link-generation code, as the
// location of this code will depend on the MediaWiki version being used.
// $Title must always be a valid Title object, not a page name.
    function WikiDB_GetPaginationLinks($Offset, $Limit, $Title,
                                       $arrQueryString = array(),
                                       $IsLastPage = false,
                                       $objMessageLocaliser = null)
    {
        // Try to use the modern PrevNextNavigationRenderer if available
        if (class_exists("MediaWiki\Navigation\PrevNextNavigationRenderer")) {
            if (!isset($objMessageLocaliser))
                $objMessageLocaliser = RequestContext::getMain();
            $ClassName = "MediaWiki\\Navigation\\PrevNextNavigationRenderer";
            $objNavRenderer = new $ClassName($objMessageLocaliser);
            return $objNavRenderer->buildPrevNextNavigation($Title, $Offset, $Limit,
                                                            $arrQueryString,
                                                            $IsLastPage);
        }
        // Try to use Language::viewPrevNext if available
        elseif (is_callable("Language::viewPrevNext")) {
            global $wgLang;
            return $wgLang->viewPrevNext($Title, $Offset, $Limit, $arrQueryString,
                                         $IsLastPage);
        }
        // Fallback: generate simple Previous/Next links
        else {
            // Calculate previous and next offsets
            $prevOffset = max(0, $Offset - $Limit);
            $nextOffset = $Offset + $Limit;
    
            // Build base URL
            $url = $Title->getLocalURL();
    
            // Build query strings
            $prevQuery = $arrQueryString;
            $nextQuery = $arrQueryString;
            $prevQuery['offset'] = $prevOffset;
            $nextQuery['offset'] = $nextOffset;
    
            $links = [];
            if ($Offset > 0) {
                $prevUrl = $url . '?' . http_build_query($prevQuery);
                $links[] = '<a href="' . htmlspecialchars($prevUrl) . '">&laquo; Previous</a>';
            }
            if (!$IsLastPage) {
                $nextUrl = $url . '?' . http_build_query($nextQuery);
                $links[] = '<a href="' . htmlspecialchars($nextUrl) . '">Next &raquo;</a>';
            }
            return implode(' | ', $links);
        }
    }

// WikiDB_GetEditPageTitle()
// Backwards-compatibility function to get the Title from an EditPage object.
// The ability to access $mTitle directly was deprecated in MW 1.30, but the
// replacement getTitle() method was only introduced in MW 1.19, so we need a
// wrapper to simplify accessing this property across MW versions.
// @back-compat MW < 1.19
	function WikiDB_GetEditPageTitle($EditPage) {
		if (method_exists($EditPage, "getTitle"))
			return $EditPage->getTitle();
		else
			return $EditPage->mTitle;
	}

// IsBlank()
// A staggeringly useful function when you don't care much about the type of blank.
// We wrap in a function_exists() check so that it plays nicely when used with my
// other extensions, some of which also include this function.
if (!function_exists("IsBlank")) {
	function IsBlank($Input) {
	// is_null() returns true if $Input is unset or null
		if (is_null($Input))
			return true;
		elseif ($Input === "")
			return true;
		elseif (is_array($Input) && count($Input) == 0)
			return true;
		else
			return false;
	}
}

// sys_get_temp_dir()
// This function was added in PHP 5.2.1, so we need to emulate it for earlier PHP
// versions.  Support for PHP < 5.2.3 was dropped in MediaWiki 1.17 so this function
// is required until WikiDB raises its minimum requirements to MW 1.17 or above.
// (Based on http://uk.php.net/manual/en/function.sys-get-temp-dir.php, with
// various modifications.)
// @back-compat PHP < 5.2.1 (MW < 1.17)
if (!function_exists('sys_get_temp_dir')) {
	function sys_get_temp_dir() {
		static $TempPath = false;

	// If we have already cached a valid temp path, then return it.
		if ($TempPath)
			return $TempPath;

	// Use the appropriate environment variable, if one is defined.
		$arrPossibleEnvVars = array("TMP", "TMPDIR", "TEMP");
		foreach ($arrPossibleEnvVars as $EnvVar) {
			$TempPath = getenv($EnvVar);
			if ($TempPath)
				break;
		}

	// If no environment variable is defined, use tempnam() to create a temporary
	// file and get the path element from that.
		if (!$TempPath) {
		// We use a base path that we know will be invalid on all systems (due to the
		// bad characters in it) therefore forcing PHP to use the system's temp
		// directory.
			$TempFile = tempnam(":\n\\/?><", "");

		// If the temp file exists then PHP was able to create it in the system's
		// temp folder, so we delete the file and return the path component.
			if (file_exists($TempFile)) {
				unlink($TempFile);
				$TempPath = dirname($TempFile);
			}
		}

	// If no temp file exists then no temp directory is defined.  Return False to
	// indicate an error. This should be checked for in any function that calls this!
		if (!$TempPath)
			return false;

	// Otherwise, get the real path of the temp directory, and ensure there is no
	// trailing slash, which might be added on some systems.
		$TempPath = realpath($TempPath);
		$TempPath = preg_replace('/(\\/|\\\\)$/', "", $TempPath);

		return $TempPath;
	}
}
