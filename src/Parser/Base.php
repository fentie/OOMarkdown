<?php
namespace Markdown\Parser;

/**
 * Base, no frills implementation of a Markdown parser class
 */
class Base implements ParserInterface
{
    const BOUNDARY_BLOCK = 'B';
    const BOUNDARY_WORD_SEPARATOR = ':';
    const BOUNDARY_GENERIC = 'X';

    # Regex to match balanced [brackets].
    # Needed to insert a maximum bracked depth while converting to PHP.

    /**
     * Need a maximum square bracket parse depth or PHP will choke
     * @var integer
     */
    protected $maxBracketDepth = 6;
    /**
     * Regular expression to match square brackets
     * @var string
     */
    protected $nestedBracketsRegex = '';
    /**
     * Need a maximum URL parenthesis parse depth or PHP will choke
     * @var integer
     */
    protected $maxUrlParenthesisDepth = 4;
    /**
     * Regular expression to match parentheses inside of URLs
     * @var string
     */
    protected $nestedUrlParenthesisRegex = '';

    /**
     * List of escaped characters
     * @var string
     */
    private $escapeChars = '\`*_{}[]()>#+-.!';

    private $emptyElementSuffix;
    private $tabWidth;

    # Change to `true` to disallow markup or entities.
    protected $noMarkup = false;
    protected $noEntities = false;

    # Predefined urls and titles for reference links and images.
    var $predef_urls = array();
    var $predef_titles = array();

    # Internal hashes used during transformation.
    protected $urls = array();
    protected $titles = array();
    protected $htmlHashes = array();

    /**
     * Status flag to avoid invalid nesting.
     * @var boolean
     */
    private $insideAnchor = false;

    /**
     * Class constructor
     *
     * @param integer $tabWidth Number of spaces to use for a tab character
     * @param boolean $useXhtml Whether or not to use self-closing HTML tags
     */
    public function __construct($tabWidth = 4, $useXhtml = true)
    {
        $this->emptyElementSuffix = ($useXhtml) ? '/>' : '>';
        $this->tabWidth = $tabWidth;
    }

    /**
     * Set parser to initial state
     * @return void
     */
    public function initialize()
    {
        $this->buildRegularExpressionsForNesting();
        $this->reset();
    }

    /**
     * Clears out any data from previous parsing runs
     */
    public function reset()
    {
        # Clear global hashes.
        $this->urls = $this->predef_urls;
        $this->titles = $this->predef_titles;
        $this->htmlHashes = array();

        $this->insideAnchor = false;
    }

    /**
     * Main function. Runs pre-processing and then sends text through conversion
     * functions
     *
     * @param string $text Text to convert
     * @return string
     */
    public function transform($text)
    {
        $this->initialize();
        $this->prepareItalicsAndBold();

        $text = $this->removeUtf8Bom($text);
        $text = $this->convertLineEndings($text);
        $text = $this->tabsToSpaces($text);
        $text = $this->removeEmptyLines($text);

        // Make sure $text ends with a couple of newlines:
        $text .= "\n\n";

        # Turn block-level HTML blocks into hash entries
        $text = $this->hashHTMLBlocks($text);

        // Run document-wide methods.
        // Strip link definitions, store in hashes.
        $text = $this->stripLinkDefinitions($text);
        $text = $this->runBasicBlockGamut($text);

        // Re-initialize to default state
        $this->reset();

        return $text . "\n";
    }

    /**
     * Convert all DOS/Mac line endings to Unix newlines (\n)
     *
     * @param string $text
     * @return string
     */
    public function convertLineEndings($text)
    {
        return preg_replace('{\r\n?}', "\n", $text);
    }

    /**
     * Remove UTF-8 BOM and marker character in input, if present.
     * @param string $text
     */
    public function removeUtf8Bom($text)
    {
        return preg_replace('{^\xEF\xBB\xBF|\x1A}', '', $text);
    }
    /**
     * Converts DOS and Mac line endings to Unix (\n)
     *
     * @param string $text
     * @return string
     */
    public function convertLineEndings($text)
    {
        return preg_replace("/\r\n?/", "\n", $text);
    }

    /**
     * Converts tabs to a number of spaces set by $tabLength
     *
     * @param string  $text
     * @param integer $tabLength How many spaces a tab should equal
     * @return string
     */
    public function tabsToSpaces($text, $tabLength)
    {
        return str_replace("\t", str_repeat(' ', $tabLength), $text);
    }

    /**
     * Strip any lines consisting only of spaces and tabs. This makes subsequent
     * regexen easier to write, because we can match consecutive blank lines
     * with /\n+/ instead of something contorted like /[ \t]*\n+/
     *
     * @param string $text
     * @return string
     */
    public function removeEmptyLines($text)
    {
        return preg_replace("/^[ \t]+$/mg", '', $text);
    }

    /**
     * Populates class members with regular expressions to handle nested
     * characters
     *
     * @return void
     */
    public function buildRegularExpressionsForNesting()
    {
        $this->nestedBracketsRegex =
            str_repeat('(?>[^\[\]]+|\[', $this->maxBracketDepth) .
            str_repeat('\])*', $this->maxBracketDepth);

        $this->nestedUrlParenthesisRegex =
            str_repeat('(?>[^()\s]+|\(', $this->maxUrlParenthesisDepth) .
            str_repeat('(?>\)))*', $this->maxUrlParenthesisDepth);
    }

    public function disableMarkupParsing()
    {
        $this->noMarkup = true;
    }

    public function enableMarkupParsing()
    {
        $this->noMarkup = false;
    }

    public function disableEntityParsing()
    {
        $this->noEntities = true;
    }

    public function enableEntityParsing()
    {
        $this->noEntities = false;
    }

    /**
     * Strips link definitions from text, stores the URLs and titles in hash
     * references.
     *
     * @param string $text
     * @return string
     */
	public function stripLinkDefinitions($text)
    {
        $less_than_tab = $this->tabWidth - 1;

        // Link defs are in the form: ^[id]: url "optional title"
        $text = preg_replace_callback('{
							^[ ]{0,' . $less_than_tab . '}\[(.+)\][ ]?:	# id = $1
							  [ ]*
							  \n?				# maybe *one* newline
							  [ ]*
							(?:
							  <(.+?)>			# url = $2
							|
							  (\S+?)			# url = $3
							)
							  [ ]*
							  \n?				# maybe one newline
							  [ ]*
							(?:
								(?<=\s)			# lookbehind for whitespace
								["(]
								(.*?)			# title = $4
								[")]
								[ ]*
							)?	# title is optional
							(?:\n+|\Z)
			}xm', array($this, '_stripLinkDefinitions_callback'), $text);
        return $text;
    }

    function _stripLinkDefinitions_callback($matches)
    {
        $link_id = strtolower($matches[1]);
        $url = $matches[2] == '' ? $matches[3] : $matches[2];
        $this->urls[$link_id] = $url;
        $this->titles[$link_id] = & $matches[4];
        return ''; # String that will replace the block
    }

    /**
     * Stores pre-existing (non-Markdown) HTML blocks in a hash for later
     * retrieval.
     *
     * @param string $text
     * @return string
     */
    public function hashHTMLBlocks($text)
    {
        if ($this->noMarkup) {
            return $text;
        }

        $lessThanTab = $this->tabWidth - 1;

        /*
         * We only want to do this for block-level HTML tags, such as headers,
         * lists, and tables. That's because we still want to wrap <p>s around
         * "paragraphs" that are wrapped in non-block-level tags, such as
         * anchors, phrase emphasis, and spans. The list of tags we're looking
         * for is hard-coded:
         *
         * *  List "a" is made of tags which can be both inline or block-level.
         *    These will be treated block-level when the start tag is alone on
         *    its line, otherwise they're not matched here and will be taken as
         *    inline later.
         *
         *  *  List "b" is made of tags which are always block-level;
         */
		$blockTagsARegex = 'ins|del';
        $blockTagsBRegex = 'p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|address|' .
            'script|noscript|form|fieldset|iframe|math';

        // Regular expression for the content of a block tag.
        $nestedTagLevel = 4;
        $attr = '
			(?>				# optional tag attributes
			  \s			# starts with whitespace
			  (?>
				[^>"/]+		# text outside quotes
			  |
				/+(?!>)		# slash not followed by ">"
			  |
				"[^"]*"		# text inside double quotes (tolerate ">")
			  |
				\'[^\']*\'	# text inside single quotes (tolerate ">")
			  )*
			)?
			';
        $content =
            str_repeat('
				(?>
				  [^<]+			# content without tag
				|
				  <\2			# nested opening tag
					' . $attr . '	# attributes
					(?>
					  />
					|
					  >', $nestedTagLevel) . // end of opening tag
            '.*?' . // last level nested tag content
            str_repeat('
					  </\2\s*>	# closing nested tag
					)
				  |
					<(?!/\2\s*>	# other tags with a different name
				  )
				)*', $nestedTagLevel);
        $content2 = str_replace('\2', '\3', $content);

        /*
         * First, look for nested blocks, e.g.:
         * 	<div>
         * 		<div>
         * 		tags for inner block must be indented.
         * 		</div>
         * 	</div>
         *
         * The outermost tags must start at the left margin for this to match,
         * and the inner nested divs must be indented.
         * We need to do this before the next, more liberal match, because the
         * next match will start at the first `<div>` and stop at the first
         * `</div>`.
         */
        $text = preg_replace_callback('{(?>
			(?>
				(?<=\n\n)		# Starting after a blank line
				|				# or
				\A\n?			# the beginning of the doc
			)
			(						# save in $1

			  # Match from `\n<tag>` to `</tag>\n`, handling nested tags
			  # in between.

						[ ]{0,' . $lessThanTab . '}
						<(' . $blockTagsBRegex . ')# start tag = $2
						' . $attr . '>			# attributes followed by > and \n
						' . $content . '		# content, support nesting
						</\2>				# the matching end tag
						[ ]*				# trailing spaces/tabs
						(?=\n+|\Z)	# followed by a newline or end of document

			| # Special version for tags of group a.

						[ ]{0,' . $lessThanTab . '}
						<(' . $blockTagsARegex . ')# start tag = $3
						' . $attr . '>[ ]*\n	# attributes followed by >
						' . $content2 . '		# content, support nesting
						</\3>				# the matching end tag
						[ ]*				# trailing spaces/tabs
						(?=\n+|\Z)	# followed by a newline or end of document

			| # Special case just for <hr />. It was easier to make a special
			  # case than to make the other regex more complicated.

						[ ]{0,' . $lessThanTab . '}
						<(hr)				# start tag = $2
						' . $attr . '			# attributes
						/?>					# the matching end tag
						[ ]*
						(?=\n{2,}|\Z)		# followed by a blank line or end of document

			| # Special case for standalone HTML comments:

					[ ]{0,' . $lessThanTab . '}
					(?s:
						<!-- .*? -->
					)
					[ ]*
					(?=\n{2,}|\Z)		# followed by a blank line or end of document

			| # PHP and ASP-style processor instructions (<? and <%)

					[ ]{0,' . $lessThanTab . '}
					(?s:
						<([?%])			# $2
						.*?
						\2>
					)
					[ ]*
					(?=\n{2,}|\Z)		# followed by a blank line or end of document

			)
			)}Sxmi', array($this, '_hashHTMLBlocks_callback'), $text);

        return $text;
    }

    function _hashHTMLBlocks_callback($matches)
    {
        $text = $matches[1];
        $key = $this->hashBlock($text);
        return "\n\n$key\n\n";
    }

    /**
     * Called whenever a tag must be hashed when a function insert an atomic
     * element in the text stream. Passing $text to through this function gives
     * a unique text-token which will be reverted back when calling unhash.
     *
     * @staticvar integer $i
     * @param string      $text
     * @param string      $boundary Use class constants
     * @return string
     */
    public function hashPart($text, $boundary = self::BOUNDARY_GENERIC)
    {
        /*
         * The $boundary argument specify what character should be used to
         * surround the token. By convension, "B" is used for block elements
         * that needs not to be wrapped into paragraph tags at the end, ":" is
         * used for elements that are word separators and "X" is used in the
         * general case.
         *
         * Swap back any tag hash found in $text so we do not have to `unhash`
         * multiple times at the end.
         */
        $text = $this->unhash($text);

        // Then hash the block.
        static $i = 0;
        $key = "$boundary\x1A" . ++$i . $boundary;
        $this->htmlHashes[$key] = $text;
        return $key; // String that will replace the tag.
    }

    /**
     * Shortcut function for hashPart with block-level boundaries.
     * @param string $text
     * @return string
     */
    function hashBlock($text)
    {
        return $this->hashPart($text, self::BOUNDARY_BLOCK);
    }

    #
    # Run block gamut tranformations.
    #
    # We need to escape raw HTML in Markdown source before doing anything
    # else. This need to be done for each block, and not only at the
    # begining in the Markdown function since hashed blocks can be part of
    # list items and could have been indented. Indented blocks would have
    # been seen as a code block in a previous pass of hashHTMLBlocks.
    function runBlockGamut($text)
    {
        $text = $this->hashHTMLBlocks($text);

        return $this->runBasicBlockGamut($text);
    }

    #
    # Run block gamut tranformations, without hashing HTML blocks. This is
    # useful when HTML blocks are known to be already hashed, like in the first
    # whole-document pass.
    #
    function runBasicBlockGamut($text)
    {
		$text = $this->doHeaders($text);
        $text = $this->doHorizontalRules($text);
        $text = $this->doLists($text);
        $text = $this->doCodeBlocks($text);
        $text = $this->doBlockQuotes($text);

        // Finally form paragraph and restore hashed blocks.
        $text = $this->formParagraphs($text);

        return $text;
    }

    /**
     * Process text for <hr> tags
     *
     * @param string $text
     * @return string
     */
    public function doHorizontalRules($text)
    {
        return preg_replace(
            '{
				^[ ]{0,3}	# Leading space
				([-*_])		# $1: First marker
				(?>			# Repeated marker group
					[ ]{0,2}	# Zero, one, or two spaces.
					\1			# Marker character
				){2,}		# Group repeated at least twice
				[ ]*		# Tailing spaces
				$			# End of line.
			}mx', "\n" . $this->hashBlock("<hr$this->emptyElementSuffix") . "\n", $text);
    }

    /**
     * Run tranformations on inline tags
     *
     * @param string $text
     * @return string
     */
    public function runSpanGamut($text)
    {
        $text = $this->parseSpan($text);
        $text = $this->doImages($text);
        $text = $this->doAnchors($text);
        $text = $this->doAutoLinks($text);
        $text = $this->encodeAmpsAndAngles($text);
        $text = $this->doItalicsAndBold($text);
        $text = $this->doHardBreaks($text);

        return $text;
    }

    /**
     * Transforms paired newline characters into <br> tags
     *
     * @param string $text
     * @return string
     */
    public function doHardBreaks($text)
    {
        return preg_replace_callback('/ {2,}\n/', array($this, '_doHardBreaks_callback'), $text);
    }

    function _doHardBreaks_callback($matches)
    {
        return $this->hashPart("<br$this->emptyElementSuffix\n");
    }

    /**
     * Turn Markdown links into HTML anchor tags
     *
     * @param string $text
     * @return string
     */
    public function doAnchors($text)
    {
		if ($this->insideAnchor) {
            return $text;
        }
        $this->insideAnchor = true;

        // First, handle reference-style links: [link text] [id]
		$text = preg_replace_callback('{
			(					# wrap whole match in $1
			  \[
				(' . $this->nestedBracketsRegex . ')	# link text = $2
			  \]

			  [ ]?				# one optional space
			  (?:\n[ ]*)?		# one optional newline followed by spaces

			  \[
				(.*?)		# id = $3
			  \]
			)
			}xs', array($this, '_doAnchors_reference_callback'), $text);

        // Next, inline-style links: [link text](url "optional title")
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  \[
				(' . $this->nestedBracketsRegex . ')	# link text = $2
			  \]
			  \(			# literal paren
				[ \n]*
				(?:
					<(.+?)>	# href = $3
				|
					(' . $this->nestedUrlParenthesisRegex . ')	# href = $4
				)
				[ \n]*
				(			# $5
				  ([\'"])	# quote char = $6
				  (.*?)		# Title = $7
				  \6		# matching quote
				  [ \n]*	# ignore any spaces/tabs between closing quote and )
				)?			# title is optional
			  \)
			)
			}xs', array($this, '_doAnchors_inline_callback'), $text);

        /*
         * Last, handle reference-style shortcuts: [link text]
         * These must come last in case you've also got [link text][1]
         * or [link text](/foo)
         */
		$text = preg_replace_callback('{
			(					# wrap whole match in $1
			  \[
				([^\[\]]+)		# link text = $2; can\'t contain [ or ]
			  \]
			)
			}xs', array($this, '_doAnchors_reference_callback'), $text);

        $this->insideAnchor = false;
        return $text;
    }

    function _doAnchors_reference_callback($matches)
    {
        $whole_match = $matches[1];
        $link_text = $matches[2];
        $link_id = & $matches[3];

        if ($link_id == "") {
            // for shortcut links like [this][] or [this].
            $link_id = $link_text;
        }

        // lower-case and turn embedded newlines into spaces
        $link_id = preg_replace('{[ ]?\n}', ' ', strtolower($link_id));

        $result = '';
        if (isset($this->urls[$link_id])) {
            $url = $this->urls[$link_id];
            $url = $this->encodeAttribute($url);

            $result = "<a href=\"$url\"";
            if (isset($this->titles[$link_id])) {
                $title = $this->titles[$link_id];
                $title = $this->encodeAttribute($title);
                $result .= " title=\"$title\"";
            }

            $link_text = $this->runSpanGamut($link_text);
            $result .= ">$link_text</a>";
            $result = $this->hashPart($result);
        } else {
            $result = $whole_match;
        }
        return $result;
    }

    function _doAnchors_inline_callback($matches)
    {
        $link_text = $this->runSpanGamut($matches[2]);
        $url = $matches[3] == '' ? $matches[4] : $matches[3];
        $title = & $matches[7];

        $url = $this->encodeAttribute($url);

        $result = "<a href=\"$url\"";
        if (isset($title)) {
            $title = $this->encodeAttribute($title);
            $result .= " title=\"$title\"";
        }

        $link_text = $this->runSpanGamut($link_text);
        $result .= ">$link_text</a>";

        return $this->hashPart($result);
    }

    /**
     * Turn Markdown image shortucts into <img> tags
     * @param string $text
     * @return string
     */
    function doImages($text)
    {
        /*
         * First, handle reference-style labeled images: ![alt text][id]
         */
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  !\[
				(' . $this->nestedBracketsRegex . ')		# alt text = $2
			  \]

			  [ ]?				# one optional space
			  (?:\n[ ]*)?		# one optional newline followed by spaces

			  \[
				(.*?)		# id = $3
			  \]

			)
			}xs', array($this, '_doImages_reference_callback'), $text);

        /*
         * Next, handle inline images:  ![alt text](url "optional title")
         * Don't forget: encode * and _
         */
		$text = preg_replace_callback('{
			(				# wrap whole match in $1
			  !\[
				(' . $this->nestedBracketsRegex . ')		# alt text = $2
			  \]
			  \s?			# One optional whitespace character
			  \(			# literal paren
				[ \n]*
				(?:
					<(\S*)>	# src url = $3
				|
					(' . $this->nestedUrlParenthesisRegex . ')	# src url = $4
				)
				[ \n]*
				(			# $5
				  ([\'"])	# quote char = $6
				  (.*?)		# title = $7
				  \6		# matching quote
				  [ \n]*
				)?			# title is optional
			  \)
			)
			}xs', array($this, '_doImages_inline_callback'), $text);

        return $text;
    }

    function _doImages_reference_callback($matches)
    {
        $whole_match = $matches[1];
        $alt_text = $matches[2];
        $link_id = strtolower($matches[3]);

        if ($link_id == "") {
            $link_id = strtolower($alt_text); # for shortcut links like ![this][].
        }

        $alt_text = $this->encodeAttribute($alt_text);
        if (isset($this->urls[$link_id])) {
            $url = $this->encodeAttribute($this->urls[$link_id]);
            $result = "<img src=\"$url\" alt=\"$alt_text\"";
            if (isset($this->titles[$link_id])) {
                $title = $this->titles[$link_id];
                $title = $this->encodeAttribute($title);
                $result .= " title=\"$title\"";
            }
            $result .= $this->emptyElementSuffix;
            $result = $this->hashPart($result);
        } else {
            # If there's no such link ID, leave intact:
            $result = $whole_match;
        }

        return $result;
    }

    function _doImages_inline_callback($matches)
    {
        $alt_text = $matches[2];
        $url = $matches[3] == '' ? $matches[4] : $matches[3];
        $title = & $matches[7];

        $alt_text = $this->encodeAttribute($alt_text);
        $url = $this->encodeAttribute($url);
        $result = "<img src=\"$url\" alt=\"$alt_text\"";
        if (isset($title)) {
            $title = $this->encodeAttribute($title);
            $result .= " title=\"$title\""; # $title already quoted
        }
        $result .= $this->emptyElementSuffix;

        return $this->hashPart($result);
    }

    function doHeaders($text)
    {
        # Setext-style headers:
        #	  Header 1
        #	  ========
        #
        #	  Header 2
        #	  --------
        #
		$text = preg_replace_callback('{ ^(.+?)[ ]*\n(=+|-+)[ ]*\n+ }mx', array($this, '_doHeaders_callback_setext'), $text);

        # atx-style headers:
        #	# Header 1
        #	## Header 2
        #	## Header 2 with closing hashes ##
        #	...
        #	###### Header 6
        #
		$text = preg_replace_callback('{
				^(\#{1,6})	# $1 = string of #\'s
				[ ]*
				(.+?)		# $2 = Header text
				[ ]*
				\#*			# optional closing #\'s (not counted)
				\n+
			}xm', array($this, '_doHeaders_callback_atx'), $text);

        return $text;
    }

    function _doHeaders_callback_setext($matches)
    {
        # Terrible hack to check we haven't found an empty list item.
        if ($matches[2] == '-' && preg_match('{^-(?: |$)}', $matches[1]))
            return $matches[0];

        $level = $matches[2]{0} == '=' ? 1 : 2;
        $block = "<h$level>" . $this->runSpanGamut($matches[1]) . "</h$level>";
        return "\n" . $this->hashBlock($block) . "\n\n";
    }

    function _doHeaders_callback_atx($matches)
    {
        $level = strlen($matches[1]);
        $block = "<h$level>" . $this->runSpanGamut($matches[2]) . "</h$level>";
        return "\n" . $this->hashBlock($block) . "\n\n";
    }

    /**
     * Form HTML ordered (numbered) and unordered (bulleted) lists.
     *
     * @param string $text
     * @return string
     */
    function doLists($text)
    {
		$less_than_tab = $this->tabWidth - 1;

        # Re-usable patterns to match list item bullets and number markers:
        $marker_ul_re = '[*+-]';
        $marker_ol_re = '\d+[.]';

        $markers_relist = array(
            $marker_ul_re => $marker_ol_re,
            $marker_ol_re => $marker_ul_re,
        );

        foreach ($markers_relist as $marker_re => $other_marker_re) {
            # Re-usable pattern to match any entirel ul or ol list:
            $whole_list_re = '
				(								# $1 = whole list
				  (								# $2
					([ ]{0,' . $less_than_tab . '})	# $3 = number of spaces
					(' . $marker_re . ')			# $4 = first list item marker
					[ ]+
				  )
				  (?s:.+?)
				  (								# $5
					  \z
					|
					  \n{2,}
					  (?=\S)
					  (?!						# Negative lookahead for another list item marker
						[ ]*
						' . $marker_re . '[ ]+
					  )
					|
					  (?=						# Lookahead for another kind of list
					    \n
						\3						# Must have the same indentation
						' . $other_marker_re . '[ ]+
					  )
				  )
				)
			'; // mx
            # We use a different prefix before nested lists than top-level lists.
            # See extended comment in _ProcessListItems().

            if ($this->list_level) {
                $text = preg_replace_callback('{
						^
						' . $whole_list_re . '
					}mx', array($this, '_doLists_callback'), $text);
            } else {
                $text = preg_replace_callback('{
						(?:(?<=\n)\n|\A\n?) # Must eat the newline
						' . $whole_list_re . '
					}mx', array($this, '_doLists_callback'), $text);
            }
        }

        return $text;
    }

    function _doLists_callback($matches)
    {
        # Re-usable patterns to match list item bullets and number markers:
        $marker_ul_re = '[*+-]';
        $marker_ol_re = '\d+[.]';
        $marker_any_re = "(?:$marker_ul_re|$marker_ol_re)";

        $list = $matches[1];
        $list_type = preg_match("/$marker_ul_re/", $matches[4]) ? "ul" : "ol";

        $marker_any_re = ( $list_type == "ul" ? $marker_ul_re : $marker_ol_re );

        $list .= "\n";
        $result = $this->processListItems($list, $marker_any_re);

        $result = $this->hashBlock("<$list_type>\n" . $result . "</$list_type>");
        return "\n" . $result . "\n\n";
    }

    var $list_level = 0;

    #
    #	Process the contents of a single ordered or unordered list, splitting it
    #	into individual list items.
    #
    # The $this->list_level global keeps track of when we're inside a list.
    # Each time we enter a list, we increment it; when we leave a list,
    # we decrement. If it's zero, we're not in a list anymore.
    #
    # We do this because when we're not inside a list, we want to treat
    # something like this:
    #
    #		I recommend upgrading to version
    #		8. Oops, now this line is treated
    #		as a sub-list.
    #
    # As a single paragraph, despite the fact that the second line starts
    # with a digit-period-space sequence.
    #
    # Whereas when we're inside a list (or sub-list), that line will be
    # treated as the start of a sub-list. What a kludge, huh? This is
    # an aspect of Markdown's syntax that's hard to parse perfectly
    # without resorting to mind-reading. Perhaps the solution is to
    # change the syntax rules such that sub-lists must start with a
    # starting cardinal number; e.g. "1." or "a.".
    function processListItems($list_str, $marker_any_re)
    {
        $this->list_level++;

        # trim trailing blank lines:
        $list_str = preg_replace("/\n{2,}\\z/", "\n", $list_str);

        $list_str = preg_replace_callback('{
			(\n)?							# leading line = $1
			(^[ ]*)							# leading whitespace = $2
			(' . $marker_any_re . '				# list marker and space = $3
				(?:[ ]+|(?=\n))	# space only required if item is not empty
			)
			((?s:.*?))						# list item text   = $4
			(?:(\n+(?=\n))|\n)				# tailing blank line = $5
			(?= \n* (\z | \2 (' . $marker_any_re . ') (?:[ ]+|(?=\n))))
			}xm', array($this, '_processListItems_callback'), $list_str);

        $this->list_level--;
        return $list_str;
    }

    function _processListItems_callback($matches)
    {
        $item = $matches[4];
        $leading_line = & $matches[1];
        $leading_space = & $matches[2];
        $marker_space = $matches[3];
        $tailing_blank_line = & $matches[5];

        if ($leading_line || $tailing_blank_line ||
            preg_match('/\n{2,}/', $item)) {
            # Replace marker with the appropriate whitespace indentation
            $item = $leading_space . str_repeat(' ', strlen($marker_space)) . $item;
            $item = $this->runBlockGamut($this->outdent($item) . "\n");
        } else {
            # Recursion for sub-lists:
            $item = $this->doLists($this->outdent($item));
            $item = preg_replace('/\n+$/', '', $item);
            $item = $this->runSpanGamut($item);
        }

        return "<li>" . $item . "</li>\n";
    }

    /**
     * Process Markdown `<pre><code>` blocks.
     * @param string $text
     * @return string
     */
    function doCodeBlocks($text)
    {
		$text = preg_replace_callback('{
				(?:\n\n|\A\n?)
				(	            # $1 = the code block -- one or more lines, starting with a space/tab
				  (?>
					[ ]{' . $this->tabWidth . '}  # Lines must start with a tab or a tab-width of spaces
					.*\n+
				  )+
				)
				((?=^[ ]{0,' . $this->tabWidth . '}\S)|\Z)	# Lookahead for non-space at line-start, or end of doc
			}xm', array($this, '_doCodeBlocks_callback'), $text);

        return $text;
    }

    function _doCodeBlocks_callback($matches)
    {
        $codeblock = $matches[1];

        $codeblock = $this->outdent($codeblock);
        $codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);

        # trim leading newlines and trailing newlines
        $codeblock = preg_replace('/\A\n+|\n+\z/', '', $codeblock);

        $codeblock = "<pre><code>$codeblock\n</code></pre>";
        return "\n\n" . $this->hashBlock($codeblock) . "\n\n";
    }

    function makeCodeSpan($code)
    {
        #
        # Create a code span markup for $code. Called from handleSpanToken.
        #
		$code = htmlspecialchars(trim($code), ENT_NOQUOTES);
        return $this->hashPart("<code>$code</code>");
    }

    var $em_relist = array(
        '' => '(?:(?<!\*)\*(?!\*)|(?<!_)_(?!_))(?=\S|$)(?![.,:;]\s)',
        '*' => '(?<=\S|^)(?<!\*)\*(?!\*)',
        '_' => '(?<=\S|^)(?<!_)_(?!_)',
    );
    var $strong_relist = array(
        '' => '(?:(?<!\*)\*\*(?!\*)|(?<!_)__(?!_))(?=\S|$)(?![.,:;]\s)',
        '**' => '(?<=\S|^)(?<!\*)\*\*(?!\*)',
        '__' => '(?<=\S|^)(?<!_)__(?!_)',
    );
    var $em_strong_relist = array(
        '' => '(?:(?<!\*)\*\*\*(?!\*)|(?<!_)___(?!_))(?=\S|$)(?![.,:;]\s)',
        '***' => '(?<=\S|^)(?<!\*)\*\*\*(?!\*)',
        '___' => '(?<=\S|^)(?<!_)___(?!_)',
    );
    var $em_strong_prepared_relist;

    #
    # Prepare regular expressions for searching emphasis tokens in any
    # context.
    #
    function prepareItalicsAndBold()
    {
		foreach ($this->em_relist as $em => $em_re) {
            foreach ($this->strong_relist as $strong => $strong_re) {
                # Construct list of allowed token expressions.
                $token_relist = array();
                if (isset($this->em_strong_relist["$em$strong"])) {
                    $token_relist[] = $this->em_strong_relist["$em$strong"];
                }
                $token_relist[] = $em_re;
                $token_relist[] = $strong_re;

                # Construct master expression from list.
                $token_re = '{(' . implode('|', $token_relist) . ')}';
                $this->em_strong_prepared_relist["$em$strong"] = $token_re;
            }
        }
    }

    function doItalicsAndBold($text)
    {
        $token_stack = array('');
        $text_stack = array('');
        $em = '';
        $strong = '';
        $tree_char_em = false;

        while (1) {
            #
            # Get prepared regular expression for seraching emphasis tokens
            # in current context.
            #
			$token_re = $this->em_strong_prepared_relist["$em$strong"];

            #
            # Each loop iteration search for the next emphasis token.
            # Each token is then passed to handleSpanToken.
            #
			$parts = preg_split($token_re, $text, 2, PREG_SPLIT_DELIM_CAPTURE);
            $text_stack[0] .= $parts[0];
            $token = & $parts[1];
            $text = & $parts[2];

            if (empty($token)) {
                # Reached end of text span: empty stack without emitting.
                # any more emphasis.
                while ($token_stack[0]) {
                    $text_stack[1] .= array_shift($token_stack);
                    $text_stack[0] .= array_shift($text_stack);
                }
                break;
            }

            $token_len = strlen($token);
            if ($tree_char_em) {
                # Reached closing marker while inside a three-char emphasis.
                if ($token_len == 3) {
                    # Three-char closing marker, close em and strong.
                    array_shift($token_stack);
                    $span = array_shift($text_stack);
                    $span = $this->runSpanGamut($span);
                    $span = "<strong><em>$span</em></strong>";
                    $text_stack[0] .= $this->hashPart($span);
                    $em = '';
                    $strong = '';
                } else {
                    # Other closing marker: close one em or strong and
                    # change current token state to match the other
                    $token_stack[0] = str_repeat($token{0}, 3 - $token_len);
                    $tag = $token_len == 2 ? "strong" : "em";
                    $span = $text_stack[0];
                    $span = $this->runSpanGamut($span);
                    $span = "<$tag>$span</$tag>";
                    $text_stack[0] = $this->hashPart($span);
                    $$tag = ''; # $$tag stands for $em or $strong
                }
                $tree_char_em = false;
            } else if ($token_len == 3) {
                if ($em) {
                    # Reached closing marker for both em and strong.
                    # Closing strong marker:
                    for ($i = 0; $i < 2; ++$i) {
                        $shifted_token = array_shift($token_stack);
                        $tag = strlen($shifted_token) == 2 ? "strong" : "em";
                        $span = array_shift($text_stack);
                        $span = $this->runSpanGamut($span);
                        $span = "<$tag>$span</$tag>";
                        $text_stack[0] .= $this->hashPart($span);
                        $$tag = ''; # $$tag stands for $em or $strong
                    }
                } else {
                    # Reached opening three-char emphasis marker. Push on token
                    # stack; will be handled by the special condition above.
                    $em = $token{0};
                    $strong = "$em$em";
                    array_unshift($token_stack, $token);
                    array_unshift($text_stack, '');
                    $tree_char_em = true;
                }
            } else if ($token_len == 2) {
                if ($strong) {
                    # Unwind any dangling emphasis marker:
                    if (strlen($token_stack[0]) == 1) {
                        $text_stack[1] .= array_shift($token_stack);
                        $text_stack[0] .= array_shift($text_stack);
                    }
                    # Closing strong marker:
                    array_shift($token_stack);
                    $span = array_shift($text_stack);
                    $span = $this->runSpanGamut($span);
                    $span = "<strong>$span</strong>";
                    $text_stack[0] .= $this->hashPart($span);
                    $strong = '';
                } else {
                    array_unshift($token_stack, $token);
                    array_unshift($text_stack, '');
                    $strong = $token;
                }
            } else {
                # Here $token_len == 1
                if ($em) {
                    if (strlen($token_stack[0]) == 1) {
                        # Closing emphasis marker:
                        array_shift($token_stack);
                        $span = array_shift($text_stack);
                        $span = $this->runSpanGamut($span);
                        $span = "<em>$span</em>";
                        $text_stack[0] .= $this->hashPart($span);
                        $em = '';
                    } else {
                        $text_stack[0] .= $token;
                    }
                } else {
                    array_unshift($token_stack, $token);
                    array_unshift($text_stack, '');
                    $em = $token;
                }
            }
        }
        return $text_stack[0];
    }

    function doBlockQuotes($text)
    {
        $text = preg_replace_callback('/
			  (								# Wrap whole match in $1
				(?>
				  ^[ ]*>[ ]?			# ">" at the start of a line
					.+\n					# rest of the first line
				  (.+\n)*					# subsequent consecutive lines
				  \n*						# blanks
				)+
			  )
			/xm', array($this, '_doBlockQuotes_callback'), $text);

        return $text;
    }

    function _doBlockQuotes_callback($matches)
    {
        $bq = $matches[1];
        # trim one level of quoting - trim whitespace-only lines
        $bq = preg_replace('/^[ ]*>[ ]?|^[ ]+$/m', '', $bq);
        $bq = $this->runBlockGamut($bq);  # recurse

        $bq = preg_replace('/^/m', "  ", $bq);
        # These leading spaces cause problem with <pre> content,
        # so we need to fix that:
        $bq = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', array($this, '_doBlockQuotes_callback2'), $bq);

        return "\n" . $this->hashBlock("<blockquote>\n$bq\n</blockquote>") . "\n\n";
    }

    function _doBlockQuotes_callback2($matches)
    {
        $pre = $matches[1];
        $pre = preg_replace('/^  /m', '', $pre);
        return $pre;
    }

    function formParagraphs($text)
    {
        #
        #	Params:
        #		$text - string to process with html <p> tags
        #
		# Strip leading and trailing lines:
        $text = preg_replace('/\A\n+|\n+\z/', '', $text);

        $grafs = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);

        #
        # Wrap <p> tags and unhashify HTML blocks
        #
		foreach ($grafs as $key => $value) {
            if (!preg_match('/^B\x1A[0-9]+B$/', $value)) {
                # Is a paragraph.
                $value = $this->runSpanGamut($value);
                $value = preg_replace('/^([ ]*)/', "<p>", $value);
                $value .= "</p>";
                $grafs[$key] = $this->unhash($value);
            } else {
                # Is a block.
                # Modify elements of @grafs in-place...
                $graf = $value;
                $block = $this->htmlHashes[$graf];
                $graf = $block;
//				if (preg_match('{
//					\A
//					(							# $1 = <div> tag
//					  <div  \s+
//					  [^>]*
//					  \b
//					  markdown\s*=\s*  ([\'"])	#	$2 = attr quote char
//					  1
//					  \2
//					  [^>]*
//					  >
//					)
//					(							# $3 = contents
//					.*
//					)
//					(</div>)					# $4 = closing tag
//					\z
//					}xs', $block, $matches))
//				{
//					list(, $div_open, , $div_content, $div_close) = $matches;
//
//					# We can't call Markdown(), because that resets the hash;
//					# that initialization code should be pulled into its own sub, though.
//					$div_content = $this->hashHTMLBlocks($div_content);
//
//					# Run document gamut methods on the content.
//					foreach ($this->document_gamut as $method => $priority) {
//						$div_content = $this->$method($div_content);
//					}
//
//					$div_open = preg_replace(
//						'{\smarkdown\s*=\s*([\'"]).+?\1}', '', $div_open);
//
//					$graf = $div_open . "\n" . $div_content . "\n" . $div_close;
//				}
                $grafs[$key] = $graf;
            }
        }

        return implode("\n\n", $grafs);
    }

    function encodeAttribute($text)
    {
        #
        # Encode text for a double-quoted HTML attribute. This function
        # is *not* suitable for attributes enclosed in single quotes.
        #
		$text = $this->encodeAmpsAndAngles($text);
        $text = str_replace('"', '&quot;', $text);
        return $text;
    }

    function encodeAmpsAndAngles($text)
    {
        #
        # Smart processing for ampersands and angle brackets that need to
        # be encoded. Valid character entities are left alone unless the
        # no-entities mode is set.
        #
		if ($this->noEntities) {
            $text = str_replace('&', '&amp;', $text);
        } else {
            # Ampersand-encoding based entirely on Nat Irons's Amputator
            # MT plugin: <http://bumppo.net/projects/amputator/>
            $text = preg_replace('/&(?!#?[xX]?(?:[0-9a-fA-F]+|\w+);)/', '&amp;', $text);
            ;
        }
        # Encode remaining <'s
        $text = str_replace('<', '&lt;', $text);

        return $text;
    }

    function doAutoLinks($text)
    {
        $text = preg_replace_callback('{<((https?|ftp|dict):[^\'">\s]+)>}i', array($this, '_doAutoLinks_url_callback'), $text);

        # Email addresses: <address@domain.foo>
        $text = preg_replace_callback('{
			<
			(?:mailto:)?
			(
				(?:
					[-!#$%&\'*+/=?^_`.{|}~\w\x80-\xFF]+
				|
					".*?"
				)
				\@
				(?:
					[-a-z0-9\x80-\xFF]+(\.[-a-z0-9\x80-\xFF]+)*\.[a-z]+
				|
					\[[\d.a-fA-F:]+\]	# IPv4 & IPv6
				)
			)
			>
			}xi', array($this, '_doAutoLinks_email_callback'), $text);

        return $text;
    }

    function _doAutoLinks_url_callback($matches)
    {
        $url = $this->encodeAttribute($matches[1]);
        $link = "<a href=\"$url\">$url</a>";
        return $this->hashPart($link);
    }

    function _doAutoLinks_email_callback($matches)
    {
        $address = $matches[1];
        $link = $this->encodeEmailAddress($address);
        return $this->hashPart($link);
    }

    function encodeEmailAddress($addr)
    {
        #
        #	Input: an email address, e.g. "foo@example.com"
        #
	#	Output: the email address as a mailto link, with each character
        #		of the address encoded as either a decimal or hex entity, in
        #		the hopes of foiling most address harvesting spam bots. E.g.:
        #
	#	  <p><a href="&#109;&#x61;&#105;&#x6c;&#116;&#x6f;&#58;&#x66;o&#111;
        #        &#x40;&#101;&#x78;&#97;&#x6d;&#112;&#x6c;&#101;&#46;&#x63;&#111;
        #        &#x6d;">&#x66;o&#111;&#x40;&#101;&#x78;&#97;&#x6d;&#112;&#x6c;
        #        &#101;&#46;&#x63;&#111;&#x6d;</a></p>
        #
	#	Based by a filter by Matthew Wickline, posted to BBEdit-Talk.
        #   With some optimizations by Milian Wolff.
        #
		$addr = "mailto:" . $addr;
        $chars = preg_split('/(?<!^)(?!$)/', $addr);
        $seed = (int) abs(crc32($addr) / strlen($addr)); # Deterministic seed.

        foreach ($chars as $key => $char) {
            $ord = ord($char);
            # Ignore non-ascii chars.
            if ($ord < 128) {
                $r = ($seed * (1 + $key)) % 100; # Pseudo-random function.
                # roughly 10% raw, 45% hex, 45% dec
                # '@' *must* be encoded. I insist.
                if ($r > 90 && $char != '@') /* do nothing */
                    ;
                else if ($r < 45)
                    $chars[$key] = '&#x' . dechex($ord) . ';';
                else
                    $chars[$key] = '&#' . $ord . ';';
            }
        }

        $addr = implode('', $chars);
        $text = implode('', array_slice($chars, 7)); # text without `mailto:`
        $addr = "<a href=\"$addr\">$text</a>";

        return $addr;
    }

    function parseSpan($str)
    {
        #
        # Take the string $str and parse it into tokens, hashing embeded HTML,
        # escaped characters and handling code spans.
        #
		$output = '';

        $span_re = '{
				(
					\\\\[' . preg_quote($this->escapeChars) . ']
				|
					(?<![`\\\\])
					`+						# code span marker
			' . ( $this->noMarkup ? '' : '
				|
					<!--    .*?     -->		# comment
				|
					<\?.*?\?> | <%.*?%>		# processing instruction
				|
					<[/!$]?[-a-zA-Z0-9:_]+	# regular tags
					(?>
						\s
						(?>[^"\'>]+|"[^"]*"|\'[^\']*\')*
					)?
					>
			') . '
				)
				}xs';

        while (1) {
            #
            # Each loop iteration seach for either the next tag, the next
            # openning code span marker, or the next escaped character.
            # Each token is then passed to handleSpanToken.
            #
			$parts = preg_split($span_re, $str, 2, PREG_SPLIT_DELIM_CAPTURE);

            # Create token from text preceding tag.
            if ($parts[0] != "") {
                $output .= $parts[0];
            }

            # Check if we reach the end.
            if (isset($parts[1])) {
                $output .= $this->handleSpanToken($parts[1], $parts[2]);
                $str = $parts[2];
            } else {
                break;
            }
        }

        return $output;
    }

    function handleSpanToken($token, &$str)
    {
        #
        # Handle $token provided by parseSpan by determining its nature and
        # returning the corresponding value that should replace it.
        #
		switch ($token{0}) {
            case "\\":
                return $this->hashPart("&#" . ord($token{1}) . ";");
            case "`":
                # Search for end marker in remaining text.
                if (preg_match('/^(.*?[^`])' . preg_quote($token) . '(?!`)(.*)$/sm', $str, $matches)) {
                    $str = $matches[2];
                    $codespan = $this->makeCodeSpan($matches[1]);
                    return $this->hashPart($codespan);
                }
                return $token; // return as text since no ending marker found.
            default:
                return $this->hashPart($token);
        }
    }

    /**
     * Remove one level of line-leading tabs or spaces
     *
     * @param string $text
     * @return string
     * @todo verify we actually want to set the limit param to 1 here
     */
    public function outdent($text)
    {
        return preg_replace('/^(\t|[ ]{1,' . $this->tabWidth . "})/", '', $text, 1);
    }

    /**
     * Reintroduce all the tags hashed by hashHTMLBlocks.
     *
     * @param string $text
     * @return string
     */
    function unhash($text)
    {
		return preg_replace_callback('/(.)\x1A[0-9]+\1/', array($this, '_unhash_callback'), $text);
    }

    function _unhash_callback($matches)
    {
        return $this->htmlHashes[$matches[0]];
    }

}