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
 * WikiDB field type handler - for managing and accessing data types for WikiDB
 * table fields.  Types are extensible - it is easy to add new ones via
 * this class.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_TypeHandler {

// An array mapping normalised type names to the assigned callback function.
// The array is initialised to contain the built-in types.  Custom types will be
// added to it when they are defined.
	static $parrTypes = array(
		'wikistring'	=> array("pWikiDB_BuiltInTypeHandlers",
								 "pWikiStringTypeHandler"),
		'string' 		=> array("pWikiDB_BuiltInTypeHandlers",
								 "pStringTypeHandler"),
		'integer' 		=> array("pWikiDB_BuiltInTypeHandlers",
								 "pIntegerTypeHandler"),
		'number' 		=> array("pWikiDB_BuiltInTypeHandlers",
								 "pNumberTypeHandler"),
		'int' 			=> array("pWikiDB_BuiltInTypeHandlers",
								 "pIntegerTypeHandler"), // Alias for 'integer'.
		'date' 			=> array("pWikiDB_BuiltInTypeHandlers",
								 "pDateTypeHandler"),
		'image' 		=> array("pWikiDB_BuiltInTypeHandlers",
								 "pImageTypeHandler"),
		'link' 			=> array("pWikiDB_BuiltInTypeHandlers",
								 "pLinkTypeHandler"),
	);

	static function NormaliseTypeName($TypeName) {
		return strtolower(trim($TypeName));
	}

	static function AddType($TypeName, $CallbackFunction) {
		$TypeName = self::NormaliseTypeName($TypeName);
		self::$parrTypes[$TypeName] = $CallbackFunction;
	}

	static function TypeDefined($TypeName) {
		$TypeName = self::NormaliseTypeName($TypeName);

		if (isset(self::$parrTypes[$TypeName]))
			return true;
		else
			return false;
	}

// GetDefinedTypes()
// Returns an array of all types that are currently defined.  This includes both
// built-in and user defined types.
// The type names will be in canonical form and the list will be sorted
// alphabetically.
	static function GetDefinedTypes() {
		$arrTypes = array_keys(self::$parrTypes);
		sort($arrTypes);

		return $arrTypes;
	}

	static function pGetCallbackFunction($TypeName) {
		$TypeName = self::NormaliseTypeName($TypeName);

		if (isset(self::$parrTypes[$TypeName]))
			return self::$parrTypes[$TypeName];
		else
			return null;
	}

	static function IsOfType($TypeName, $Value) {
		$CallbackFunction = self::pGetCallbackFunction($TypeName);

		if (is_callable($CallbackFunction)) {
			return call_user_func($CallbackFunction, WIKIDB_Validate,
								  $Value, array());
		}
	// If the type doesn't exist, then the field is valid.
		else {
			return true;
		}
	}

// GetTypeNameForDisplay()
// Returns the text that should be displayed in the interface when referring to this
// type.
	static function GetTypeNameForDisplay($TypeName) {
		if ($TypeName == "") {
			return WikiDB_Msg('wikidb-tabledef-untyped');
		}
		elseif (self::TypeDefined($TypeName)) {
			return $TypeName;
		}
		else {
			return WikiDB_Msg('wikidb-tabledef-badtype', $TypeName);
		}
	}

	static function FormatTypeForDisplay($TypeName, $Value, $Options = array()) {
		$CallbackFunction = self::pGetCallbackFunction($TypeName);

	// Always trim() the supplied value to remove whitespace.
		$Value = trim($Value);

		if (is_callable($CallbackFunction)) {
			if (call_user_func($CallbackFunction, WIKIDB_Validate,
							   $Value, array()))
			{
				return call_user_func($CallbackFunction, WIKIDB_FormatForDisplay,
									  $Value, $Options);
			}
			else {
				return "??";
			}
		}
		else {
			return $Value;
		}
	}

	static function FormatTypeForSorting($TypeName, $Value) {
		$CallbackFunction = self::pGetCallbackFunction($TypeName);

		if (is_callable($CallbackFunction)) {
			return call_user_func($CallbackFunction, WIKIDB_FormatForSorting,
								  $Value, array());
		}
		else {
			return $Value;
		}
	}

// GuessType()
// Guesses the field type of the supplied field data.
// It does this by calling the callback function for each type-definition, invoking
// the WIKIDB_GetSimilarity hook.  The callback function should return a number from
// 0 to 10, where 10 means that the data is DEFINITELY the type in question, and 0
// means it is definitely not.  The type that returns the highest number is the
// one that is chosen.  If there is more than one type with the same highest number,
// then the first one that was defined wins. The function will return the name of the
// matched type (as a string).
// The $arrAvailableTypes may be set to an array of type names, and if so only those
// types will be checked.  This is primarily provided for unit-testing purposes, to
// avoid unit-tests from being compromised by user-defined types.  If omitted, as it
// normally will be, then all registered types will be checked.
	static function GuessType($FieldValue, $arrAvailableTypes = null) {
		$ScoredTypes = array();

	// If an array of available types has been specified, normalise all type names
	// present, and flip the array so that the key is the type name, rather than the
	// other way round.  This will make the subsequent checks cheaper.
		if (is_array($arrAvailableTypes)) {
			foreach ($arrAvailableTypes as $Key => $Value) {
				$arrAvailableTypes[$Key] = self::NormaliseTypeName($Value);
			}

			$arrAvailableTypes = array_flip($arrAvailableTypes);
		}

		foreach (self::$parrTypes as $TypeName => $CallbackFunction) {
		// If the set of available types has been restricted, and this type is not
		// present in the array, then skip it.
			if (is_array($arrAvailableTypes)
				&& !isset($arrAvailableTypes[$TypeName]))
			{
				continue;
			}

			if (is_callable($CallbackFunction)) {
				$ScoredTypes[$TypeName] = call_user_func($CallbackFunction,
														 WIKIDB_GetSimilarity,
														 $FieldValue,
														 array());
				if ($ScoredTypes[$TypeName] >= 10)
					return $TypeName;
			}
		}

		$LastScore = -1;
		$LastType = "";
		foreach ($ScoredTypes as $TypeName => $Score) {
			if ($Score > $LastScore) {
				$LastScore = $Score;
				$LastType = $TypeName;
			}
		}

		return $LastType;
	}

} // END: class WikiDB_TypeHandler
