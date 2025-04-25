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
 * Default type handlers which are part of the WikiDB extension.
 * The system is extensible, so more types may be added if required.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class pWikiDB_BuiltInTypeHandlers {

	static function pNumberTypeHandler($Action, $Value, $Options) {
		if (isset($Options[0]) && WikiDB_IsNumeric($Options[0]))
			$MinValue = WikiDB_StrToNumber($Options[0]);
		if (isset($Options[1]) && WikiDB_IsNumeric($Options[1]))
			$MaxValue = WikiDB_StrToNumber($Options[1]);

		switch ($Action) {
			case WIKIDB_Validate:
				if (!WikiDB_IsNumeric($Value))
					return false;

				$Value = WikiDB_StrToNumber($Value);

				if (isset($MinValue) && $Value < $MinValue)
					return false;
				if (isset($MaxValue) && $Value > $MaxValue)
					return false;

				return true;

			case WIKIDB_GetSimilarity:
				if (WikiDB_IsValidNumberFormat($Value))
					return 10;
				else
					return 0;

			case WIKIDB_FormatForDisplay:
				if ($Value == "")
					return "";

			// Delocalise the string, so it is in a standard format.
				$Value = WikiDB_DelocaliseNumericString($Value);

			// Ensure the number is within the expected range, if one is specified.
			// TODO: We currently convert to float values to perform this comparison,
			//		 but this may give incorrect results for large numbers.  Ideally
			//		 this comparison should handle numbers of arbitrary precision.
				$ConvertedValue = floatval($Value);
				if (isset($MinValue) && $ConvertedValue < $MinValue)
					return "";
				if (isset($MaxValue) && $ConvertedValue > $MaxValue)
					return "";

				if (strpos(strval($Value), ".") !== false)
					return WikiDB_FormatFloat($Value);
				else
					return WikiDB_FormatInteger($Value);

			case WIKIDB_FormatForSorting:
				return WikiDB_NumberToSortableString($Value);
		}
	}

	static function pIntegerTypeHandler($Action, $Value, $Options) {
		if (isset($Options[0]) && WikiDB_IsNumeric($Options[0]))
			$MinValue = intval($Options[0]);
		if (isset($Options[1]) && WikiDB_IsNumeric($Options[1]))
			$MaxValue = intval($Options[1]);

		switch ($Action) {
			case WIKIDB_Validate:
				if (!WikiDB_IsInteger($Value))
					return false;

				$Value = floatval($Value);

				if (isset($MinValue) && $Value < $MinValue)
					return false;
				if (isset($MaxValue) && $Value > $MaxValue)
					return false;

				return true;

			case WIKIDB_GetSimilarity:
			// If this is a valid number (using a strict, locale-aware comparison)
			// and is also a valid integer (using a loose comparison) then we treat
			// it is as similar.
				if (WikiDB_IsValidNumberFormat($Value) && WikiDB_IsInteger($Value))
					return 10;
				else
					return 0;

			case WIKIDB_FormatForDisplay:
				if (!WikiDB_IsInteger($Value))
					return "";

			// Delocalise the string, so it is in a standard format.
				$Value = WikiDB_DelocaliseNumericString($Value);

			// Ensure the number is within the expected range, if one is specified.
			// TODO: We currently convert to float values to perform this comparison,
			//		 but this may give incorrect results for large numbers.  Ideally
			//		 this comparison should handle numbers of arbitrary precision.
				$ConvertedValue = floatval($Value);
				if (isset($MinValue) && $ConvertedValue < $MinValue)
					return "";
				if (isset($MaxValue) && $ConvertedValue > $MaxValue)
					return "";

			// Return the value formatted using the current MediaWiki locale.
				return WikiDB_FormatInteger($Value);

			case WIKIDB_FormatForSorting:
				return WikiDB_NumberToSortableString($Value);
		}
	}

	static function pWikiStringTypeHandler($Action, $Value, $Options) {
		switch ($Action) {
			case WIKIDB_Validate:
				return true;

			case WIKIDB_GetSimilarity:
				return 0;

			case WIKIDB_FormatForDisplay:
				return $Value;

			case WIKIDB_FormatForSorting:
			// TODO: Ideally we would strip all wiki markup, but that's not so easy.
			//		 Perhaps parse it, then replace all HTML markup with spaces?
			//		 Some thought needed here...
				return $Value;
		}
	}

	static function pStringTypeHandler($Action, $Value, $Options) {
		if (isset($Options[0]) && WikiDB_IsNumeric($Options[0]))
			$MaxLen = intval($Options[0]);

		switch ($Action) {
			case WIKIDB_Validate:
				if (isset($MaxLen) && strlen($Value) > $MaxLen)
					return false;
				else
					return true;

			case WIKIDB_GetSimilarity:
				return 0;

			case WIKIDB_FormatForDisplay:
				if ($Value == "")
					return "";

			// Truncate value to the maximum length permitted for the field, if one
			// is set.
				if (isset($MaxLen))
					$Value = substr($Value, 0, $MaxLen);

			// Output the string in <nowiki> tags to ensure the text is not
			// interpreted as wiki markup.  We also ensure the output is escaped
			// properly so it is not treated as HTML.
			// TODO: Should we convert multiple spaces to &nbsp;? What about
			//		 new-lines?  I don't think <pre> should be used as this would
			//		 also affect styling.
				return "<nowiki>" . WikiDB_EscapeHTML($Value) . "</nowiki>";

			case WIKIDB_FormatForSorting:
				return $Value;
		}
	}

	static function pImageTypeHandler($Action, $Value, $Options) {
	// Get any supplied scale arguments for this field.
		$Width = $Height = null;
		if (isset($Options[0]) && WikiDB_IsNumeric($Options[0]))
			$Width = intval($Options[0]);
		if (isset($Options[1]) && WikiDB_IsNumeric($Options[1]))
			$Height = intval($Options[1]);

	// If no width was specified, fill it in.  If no height was specified it
	// is ignored when we come to build the string.
		if ($Width === null) {
			if ($Height === null) {
			// If no width or height is specified, default to a small size,
			// so that in-line display is relatively compact.
				$Width = 75;
			}
			else {
			// If only a height is specified, move it to the width component.
			// The MW syntax doesn't allow specifying a height on it's own.
				$Width = $Height;
			}
		}

	// Build the size string from the width/height.
		$ScaleArgs = $Width;
		if ($Height !== null)
			$ScaleArgs .= "x" . $Height;
		if ($ScaleArgs != null)
			$ScaleArgs = "|" . $ScaleArgs . "px|";

	// Get an array of namespace names that may refer to the image namespace.
		$objContentLanguage = WikiDB_GetContentLanguage();
		$Namespaces = $objContentLanguage->getNamespaceIds();
		$arrImageNamespaces = array();
		foreach ($Namespaces as $NSName => $NSIndex) {
			if ($NSIndex == NS_FILE)
				$arrImageNamespaces[] = preg_quote($NSName, "/");
		}

		switch ($Action) {
			case WIKIDB_Validate:
			// Currently everything is a valid image (image does not need to exist
			// on the wiki).  If 'image' type is specified, then we parse it to
			// decide how to create the link (e.g. remove tags if both present,
			// remove starting colon if present, add image namespace name if not
			// present.
				return true;

			case WIKIDB_GetSimilarity:
			// If we have a link of the form [[Image:Name]] (where 'Image:' is
			// any alias for the image namespace) then this is definitely an
			// image.  If a colon is present at the start i.e. [[:Image:Name]] then
			// by default this is a link (unless the field is type-cast to image).
			// Image:Name defaults to a standard wikistring and rendered literally
			// (again, unless type-cast).
				if (preg_match('/^\[\[(' . implode("|", $arrImageNamespaces)
					. '):.*\]\]$/i', $Value))
				{
					return 10;
				}
				else {
					return 0;
				}

			case WIKIDB_FormatForDisplay:
				// drop through
			case WIKIDB_FormatForSorting:
			// Remove link brackets, if present.
				$Value = preg_replace('/^\[\[(.*)\]\]$/', "$1", $Value);
			// Remove extra image parameters, if present.
				$Value = preg_replace('/^:?((?U).*)(\|.*)?$/', "$1", $Value);

			// If result is empty, then return an empty string - there is nothing
			// to display or store.
				if ($Value == "")
					return "";

			// Get image name, which is the remaining $Value up to the first |
			// character, minus the starting colon, if present.  The namespace is
			// stored in $Matches[1], and the title in $Matches[2].  We always
			// replace the (optional) namespace with the local namespace name.
			// This regex will always pass, so need for an if block.
				preg_match('/^:?((?:' . implode("|", $arrImageNamespaces)
						 . '):)?([^|]*)/i', $Value, $Matches);

			// Create a title object (doesn't matter if the image exists or not)
			// and use it to get the canonical name of the page (so different
			// representations sort correctly).
				$Title = Title::newFromText($Matches[2], NS_FILE);

			// For display, we output the whole thing in the appropriate format.
				if ($Action == WIKIDB_FormatForDisplay)
					return "[[" . $Title->getPrefixedDBkey()  . $ScaleArgs . "]]";
			// For sorting the namespace is not strictly required, as it will always
			// be the same for all images, however including means it will sort
			// correctly in situations containing other data types.
				else
					return $Title->getPrefixedDBkey();
		}
	}

// A link is either a full piece of link markup (e.g. [[ns:page|display text]]) or
// simply the destination page (e.g. ns:page).
// For internal purposes (e.g. sorting, querying, etc.) we normalise this to the
// latter format in its canonical form (e.g. canonical namespace name, first letter
// capitalisation, etc.).
// For outputting we normalise to the first style (e.g. wrapping it in link tag
// markup) so that it is rendered properly within the wiki.
// TODO: Should we do any normalisation when outputting?
	static function pLinkTypeHandler($Action, $Value, $Options) {

		switch ($Action) {
			case WIKIDB_Validate:
			// Any string is valid as a wiki link.
				return true;

			case WIKIDB_GetSimilarity:
			// If the text is wrapped in [[ ]] then it is fairly likely to be a
			// link.  We don't make this too high though, as there may be other
			// more specific types of link (e.g. image links)
				if (preg_match('/^\[\[.*\]\]$/', $Value))
					return 5;
				else
					return 0;

			case WIKIDB_FormatForDisplay:
			// If result is empty, then return an empty string - there is nothing
			// to display.
				if ($Value == "")
					return "";

				$Link = self::pParseLink($Value);

			// Set the output to the link, as entered.
				$Output = $Link['link'];

			// If the link included display text, add it to the output.
				if (!IsBlank($Link['display']))
					$Output .= "|" . $Link['display'];

			// If this is an image or category page, add a colon so that it is
			// a link to the page, rather than having any special behaviour.
				if ($Link['title'] !== null
					&& in_array($Link['title']->getNamespace(),
								array(NS_FILE, NS_CATEGORY)))
				{
					$Output = ":" . $Output;
				}

			// Finally, add the wiki markup so it renders as a link.
				$Output = "[[" . $Output . "]]";

				return $Output;

			case WIKIDB_FormatForSorting:
			// If result is empty, then return an empty string - there is nothing
			// to store.
				if ($Value == "")
					return "";

			// We normalise the link by creating a Title object and using
			// getFullText().  This has the effect of using the correct namespace
			// name (there may be aliases, e.g. "Image" for the "File" namespace -
			// these are all resolved to the standard form) as well as tidying
			// capitalisation, etc.
			// This gives us a normalised value for searching/sorting.
			// TODO: Should we sort on the display text instead (if present)?
			//		 If not, should we append this to the sort, so that it is taken
			//		 into account if the same page is listed with different display
			//		 text?
				$Link = self::pParseLink($Value);

				if ($Link['title'] === null)
					return "";
				else
					return $Link['title']->getPrefixedDBkey();
		}
	}

// pParseLink()
// Helper function which takes a string and returns an array containing the
// following elements:
// 'title'	 => A title object derived from the link text, or null if the link text
//				is invalid (i.e. if Title::newFromText() returns null).
// 'link'	 => The literal text of the link, as entered (without any normalisation).
// 'display' => The display text, i.e. what follows the | character, if present.
//				If not present, this is an empty string.
	static function pParseLink($Value) {
		$Result = array();

	// Strip surrounding markup ([[ and ]]) if present.  We also remove
	// any initial : character, if present.  We don't check for multiple : characters
	// though.
		$Value = preg_replace('/^\[\[(?-U::?)(.*)\]\].*/U', "$1", $Value);

	// Separate out the display text from the actual link, if present.
		preg_match('/^(.+)(?:\|(.*))?$/U', $Value, $Matches);

		$Result['link'] = $Matches[1];
		if (isset($Matches[2]))
			$Result['display'] = $Matches[2];
		else
			$Result['display'] = "";
		$Result['title'] = Title::newFromText($Matches[1]);

//		print("[\n  " . $Result['link'] . "\n  " . $Result['display'] . "\n  "
//			  . $Result['title']->getFullText() . "\n]\n");

		return $Result;
	}

} // END class pWikiDB_BuiltInTypeHandlers
