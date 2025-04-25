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
 * Some regular expressions used within the WikiDB code.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

////////////////////////////////////
// Regex to match a DB field definition (single line) in a table page.
// [0] - Whole line
// [1] - Field name
// [2] - Field type
// [3] - Arguments (comma-separated list)
// [4] - Link (if it starts with # it's an alias) - square brackets not included.
// [5] - Comment, including starting dashes (rest of line)
	$pRegexDBLine =	'/^						# Anchor to start of line
					>						# All field lines start with ">"
					\s*						# Optional whitespace
					([^:[-]+)				# Field name (cannot contain ":",
											# "[" or "-")
					(?:\:\s*				# If colon is present then field type
											# is defined.
					  ([^\s[\(-]*)			# Optional :FieldType (cannot contain
											# spaces, "(", "[" or "-")
					  \s*(?:(?U)\((.*)\))?	# Optional field type arguments within
											# parentheses
					)?						# End of optional field type
					\s*						# Optional whitespace
					(?:(?U)\[\[(.*)\]\])?	# Optional link [[anything]]
					\s*						# Optional whitespace
					(?:--(.*))?				# Optional comment ("--" to end of line)
					$/x';					// x = allows these comments.

////////////////////////////////////
// Regex to match a <data> tag:
// [0] - Whole tag
// [1] - attribute portion of opening tag ('<data a="vala" b="valb">' becomes
// 		 'a="vala" b="valb"')
// [2] - Contents of the tag (between <data ...> and </data>
// Tag may be unterminated, in which case this captures the whole rest of the page.
	$pRegexDataTag = '/<\s*data\s+(.*)>(.*)(?:<\s*\/data\s*>|$)/sDiU';

////////////////////////////////////
// Regex to match quoted attribute/value pairs (e.g. within an html opening tag):
// [0] - matched string
// [1] - attribute name
// [2] - value without quotes
	$pRegexAttributeValuePairs = '/(.*)="(.*)"/sU';

////////////////////////////////////
// Regex to match all unwanted link characters.  Used to remove them from the Alias
// string.  It matches 3 characters: [, ] and #
	$pRegexUnwantedLinkChars = '/\[|\]|#/';

////////////////////////////////////
// Regex to match an ending quote character, taking into account escaping using
// backslash, which can also be used to escape itself.
// Replace XX by the end character you are looking for before use.
// [0] - matched string
// [1] - end character that was matched
	$pRegexFindEndQuote = '/
						(?:					# (non-capturing expression)
						  ^					# Start of string
						  |					#    OR
						  [^\\\\]			# Non-backslash character
						  |					#    OR
						  (?:^|[^\\\\])		# Either of the above...
							(?:\\\\\\\\)+	# ...followed by an even number of
											# slashes (i.e. 1 or more sets of \\
											# - yes we need 8 slashes to represent
											# that!)
						)
						(XX)				# The end quote that we want to capture
											# XX needs to be replaced before use
						$/x';				// x = allows these comments.

////////////////////////////////////
// Regex to replace escaped characters in a string.
// The escape character can only be used to escape itself and the specified ending
// string.
// Replace XX by the end character you are looking for before use.  You can replace
// these with . to allow all characters to be escaped, or a specific string to only
// allow the end-character to be escaped.
// Use "$1$2$3" as your replacement, and escape characters will be removed.
// You should then replace \\ with \ to remove the escaped escape-characters.
	$pRegexUnescapeString = '/
							(
							  ^					# Start of string
							  |					#    OR
							  [^\\\\]			# Non-backslash character
							)
							(?:
							  ((?:\\\\\\\\)*)	# ...followed by (optionally) an even
												# number of backslash characters
							  					#    THEN
							  \\\\(XX)			# backslash followed by end quote.
							)
							/x';				// x = allows these comments.
