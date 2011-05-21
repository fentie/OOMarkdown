<?php
//Be sure to re-add licencing information from the bottom of the file for legal
//reasons

/**
 * Markdown -- A text-to-HTML conversion tool for web writers
 *
 * @copyright 2004 John Gruber
 * @link http://daringfireball.net/projects/markdown/
 */

// Regex to match balanced [brackets]. See Friedl's
// "Mastering Regular Expressions", 2nd Ed., pp. 328-331.
//my $g_nested_brackets;
//$g_nested_brackets = qr{
//    (? >                                 # Atomic matching
//       [^\[\]]+                            # Anything other than brackets
//     |
//       \[
//         (??{ $g_nested_brackets })        # Recursive set of nested brackets
//       \]
//    )*
//}x;

// Table of hash values for escaped characters:
//my %g_escape_table;
//foreach my $char (split //, '\\`*_{}[]()>#+-.!') {
//    $g_escape_table{$char} = md5_hex($char);
//}

class Markdown
{
    protected $text               = '';
    protected $htmlBlocks         = array();
    protected $titles             = array();
    protected $urls               = array();
    protected $tabLength          = 4;
    protected $emptyElementSuffix = ' />';     # Change to ">" for HTML output
    protected $specialChars = array(
        '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '>', '#', '+', '-',
        '.', '!',
    );
    protected static $listLevel = 0;

    public function __construct($text = '')
    {
        $this->text = $text . "\n\n";
    }

    /**
     * Main function. The order in which other subs are called here is
     * essential. Link and image substitutions need to happen before
     * escapeSpecialChars(), so that any *'s or _'s in the <a> and <img> tags
     * get encoded.
     *
     * @param string $text String to convert
     * @return string
     */
    public function convert($text)
    {
        $text = $this->convertLineEndings($text);
        $text = $this->tabsToSpaces($text);
        $text = $this->removeEmptyLines($text);

        # Turn block-level HTML blocks into hash entries
        $text = $this->hashHTMLBlocks($text);

        # Strip link definitions, store in hashes.
        $text = $this->stripLinkDefinitions($text);

        $text = $this->processBlocks($text);

        $text = $this->unescapeSpecialChars($text);

        return $text . "\n";
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
     * Converts tabs to spaces. Tab length is a class property (tabLength)
     *
     * @param string $text
     * @return string
     */
    public function tabsToSpaces($text)
    {
        return str_replace("\t", str_repeat(' ', $this->tabLength), $text);
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

    # Hashify HTML blocks:
    # We only want to do this for block-level HTML tags, such as headers,
    # lists, and tables. That's because we still want to wrap <p>s around
    # "paragraphs" that are wrapped in non-block-level tags, such as anchors,
    # phrase emphasis, and spans. The list of tags we're looking for is
    # hard-coded:
    public function hashHtmlBlocks($text)
    {
        $lessThanTab = $this->tabLength - 1;
        $blockTagsA = '/p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|script|' .
            'noscript|form|fieldset|iframe|math|ins|del/';
        $blockTagsB = str_replace('math|ins|del/', 'math/', $blockTagsA);

        $matches = array();
        # First, look for nested blocks, e.g.:
        #     <div>
        #         <div>
        #         tags for inner block must be indented.
        #         </div>
        #     </div>
        #
        # The outermost tags must start at the left margin for this to match,
        # and the inner nested divs must be indented.
        # We need to do this before the next, more liberal match, because the
        # next match will start at the first `<div>` and stop at the first
        # `</div>`.

         /*{
            (                        # save in $1
                ^                    # start of line  (with /m)
                <($block_tags_a)    # start tag = $2
                \b                    # word break
                (.*\n)*?            # any number of lines, minimally matching
                </\2>                # the matching end tag
                [ \t]*                # trailing spaces/tabs
                (?=\n+|\Z)    # followed by a newline or end of document
            )
        }{
            my $key = md5_hex($1);
            $g_html_blocks{$key} = $1;
            "\n\n" . $key . "\n\n";
        }egmx;*/
        preg_match_all("@(^<(" . $blockTagsA .
            ")\b(.*\n)*?</\2>[ \t]*(?=\n+|\Z))@", $text, $matches,
            PREG_SET_ORDER);
        $this->storeMatchesInHash($matches);

        # Now match more liberally, simply from `\n<tag>` to `</tag>\n`
        /*$text =~ s{
                (                        # save in $1
                    ^                    # start of line  (with /m)
                    <($block_tags_b)    # start tag = $2
                    \b                    # word break
                    (.*\n)*?           # any number of lines, minimally matching
                    .*</\2>                # the matching end tag
                    [ \t]*                # trailing spaces/tabs
                    (?=\n+|\Z)    # followed by a newline or end of document
                )
            }{
                my $key = md5_hex($1);
                $g_html_blocks{$key} = $1;
                "\n\n" . $key . "\n\n";
            }egmx;*/
        preg_match_all("@(^<(" . $blockTagsB .
            ")\b(.*\n)*?</\2>[ \t]*(?=\n+|\Z))@", $text, $matches,
            PREG_SET_ORDER);
        $this->storeMatchesInHash($matches);


        # Special case just for <hr />. It was easier to make a special case
        //than to make the other regex more complicated.
         preg_match_all("@(?:(?<=\n\n|\A\n?)([ ]{0," . $lessThanTab .
             "}<(hr)\b([^<>])*?/?>[ \t]*(?=\n{2,}|\Z)", $text, $matches,
             PREG_SET_ORDER);
         $this->storeMatchesInHash($matches);

        # Special case for standalone HTML comments:
         preg_match_all("@(?:(?<=\n\n|\A\n?)([ ]{0," . $lessThanTab .
             "}(?s:<!(--.*?--\s*)+>)[ \t]*(?=\n{2,}|\Z)", $this->text, $matches,
             PREG_SET_ORDER);
         $this->storeMatchesInHash($matches);

         return $text;
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
        $lessThanTab = $this->tabLength - 1;
        $matches = array();
        preg_match_all('@^[ ]{0,' . $lessThanTab .
            '}\[(.+)\]:@[ \t]*\n?[ \t]*<?(\S+?)>?[ \t]*\n?[ \t]*(?:(?<=\s)' .
            '["(](.+?)[")][ \t]*)?(?:\n+|\Z)', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $url) {
            unset($url[0]);
            $this->urls[strtolower($url[1])] = htmlspecialchars($url[2]);
            if (!empty($url[3])) {
                $this->titles[strtolower($url[1])] = htmlspecialchars($url[3]);
            }
        }
        /*
         * Link defs are in the form: ^[id]: url "optional title"
         */
        return $text;
    }

    /**
     * Runs all applicable transformations to produce block-level elements
     *
     * @param string $text
     * @return string
     */
    protected function processBlocks($text)
    {
        $text = $this->processHeaders($text);

        # Do Horizontal Rules:
        $text = preg_replace("/^[ ]{0,2}([ ]?\*[ ]?){3,}[ \t]*$/mx", "\n<hr" .
            $this->emptyElementSuffix . "\n", $text);
        $text = preg_replace("/^[ ]{0,2}([ ]? -[ ]?){3,}[ \t]*$/mx", "\n<hr" .
            $this->emptyElementSuffix . "\n", $text);
        $text = preg_replace("/^[ ]{0,2}([ ]? _[ ]?){3,}[ \t]*$/mx", "\n<hr" .
            $this->emptyElementSuffix . "\n", $text);
        //$text =~ s{^[ ]{0,2}([ ]? _[ ]?){3,}[ \t]*$}{
        //\n<hr$g_empty_element_suffix\n}gmx;

        $text = $this->processLists($text);

        $text = $this->processCodeBlocks($text);

        $text = $this->processBlockQuotes($text);

        /*
         * We already ran _HashHTMLBlocks() before, in Markdown(), but that
         * was to escape raw HTML in the original Markdown source. This time,
         * we're escaping the markup we've just created, so that we don't wrap
         * <p> tags around block-level tags.
         */
        $text = $this->hashHTMLBlocks($text);

        $text = $this->formParagraphs($text);

        return $text;
    }

    protected function processHeaders($text)
    {
        # Setext-style headers:
        #      Header 1
        #      ========
        #
        #      Header 2
        #      --------
        #
        $text = preg_replace_callback("/^(.+)[ \t]*\n=+[ \t]*\n+/mx",
            create_function('input', 'return "<h1>"  .  $this->processSpans($input[1])  .  "</h1>\n\n"'), $text);
        $text = preg_replace_callback("/^(.+)[ \t]*\n-+[ \t]*\n+/mx",
            create_function('input', 'return "<h2>"  .  $this->processSpans($input[1])  .  "</h2>\n\n"'), $text);

        # atx-style headers:
        #    # Header 1
        #    ## Header 2
        #    ## Header 2 with closing hashes ##
        #    ...
        #    ###### Header 6
        #
        $text = preg_replace_callback("/^(\#{1,6})[ \t]*(.+?)[ \t]*\#*\n+/mx",
            array($this, 'atxHeader'), $text);
        return $text;
    }

    /**
     * Callback function to handle conversion of atx-style headers to HTML
     * header tags
     *
     * @param array $matches
     * @return string
     */
    protected function atxHeader($matches)
    {
        $headingLevel = strlen($matches[1]);
        return '<h' . $headingLevel . '>' . $this->processSpans($matches[2]) .
            '</' . $headingLevel . '>';
    }

    protected function processItalicsAndBold($text)
    {
        // <strong> must go first:
        $text = preg_replace("/ (\*\*|__) (?=\S) (.+?[*_]*) (?<=\S) \1 /sx",
            '<strong>$2</strong>', $text);
        $text = preg_replace("/ (\*|_) (?=\S) (.+?) (?<=\S) \1 /sx",
            '<em>$2</em>', $text);
        return $text;
    }

    /**
     * Process Markdown '<pre><code>' blocks.
     *
     * @param string $text
     * @return string
     */
    protected function processCodeBlocks($text)
    {
        $text = preg_replace_callback("/(?:\n\n|\A)((?:(?:[ ]{" . $this->tabLength .
            "}|\t).*\n+)+)((?=^[ ]{0," . $this->tabLength . "}\S)|\Z)/",
            array($this, 'processCodeBlock'), $text);
        return $text;
    }

    /**
     * Callback function to transform an individual code block
     *
     * @param array $matches
     * @return string
     */
    protected function processCodeBlock($matches)
    {
        $codeBlock = $matches[1];
        $codeBlock = $this->encodeCode($this->outdent($codeBlock));
        $codeBlock = $this->tabsToSpaces($codeBlock);
        $codeBlock = ltrim($codeBlock, "\n");
        $codeBlock = rtrim($codeBlock);
        return "\n\n<pre><code>" . $codeBlock . "\n</code></pre>\n\n";
    }

    #
    #     *    Backtick quotes are used for <code></code> spans.
    #
    #     *    You can use multiple backticks as the delimiters if you want to
    #         include literal backticks in the code span. So, this input:
    #
    #         Just type ``foo `bar` baz`` at the prompt.
    #
    #         Will translate to:
    #
    #         <p>Just type <code>foo `bar` baz</code> at the prompt.</p>
    #
    #        There's no arbitrary limit to the number of backticks you
    #        can use as delimters. If you need three consecutive backticks
    #        in your code, use four for delimiters, etc.
    #
    #    *    You can use spaces to get literal backticks at the edges:
    #
    #         ... type `` `bar` `` ...
    #
    #         Turns to:
    #
    #         ... type <code>`bar`</code> ...
    #
    protected function processCodeSpans($text)
    {
        $text = preg_replace_callback("/(`+)(.+?)(?<!`)\1(?!`)/", array($this, 'processCodeSpan'), $text);
        return $text;
    }

    /**
     * Callback function to handle processing of individual chunks of code
     * inside of <code> tags
     *
     * @param array $matches
     * @return string
     */
    protected function processCodeSpan($matches)
    {
        $code = $matches[2];
        $code = trim($code);
        $code = $this->encodeCode($code);
        return "<code>$code</code>";
    }

    /**
     * Encode/escape certain characters inside Markdown code runs. The point is
     * that in code, these characters are literals, and lose their special
     * Markdown meanings.
     *
     * @param string $text
     * @return string
     */
    protected function encodeCode($text)
    {
        $text = htmlspecialchars($text);

        // Now, escape characters that are magic in Markdown:
        $charMap = array();
        foreach ($this->specialChars as $char) {
            $charMap[$char] = md5($char);
        }
        $text = str_replace(array_keys($charMap), array_values($charMap), $text);
        //s! \* !$g_escape_table{'*'}!gx;
        //s! _  !$g_escape_table{'_'}!gx;
        //s! {  !$g_escape_table{'{'}!gx;
        //s! }  !$g_escape_table{'}'}!gx;
        //s! \[ !$g_escape_table{'['}!gx;
        //s! \] !$g_escape_table{']'}!gx;
        //s! \\ !$g_escape_table{'\\'}!gx;

        return $text;
    }

    /**
     * Add <p> tags around HTML blocks and instances of multiple newlines
     *
     * @params string $text String to which to add <p> tags
     * @return string
     */
    protected function formParagraphs($text)
    {
        $text = trim($text, "\n");
        $paragraphs = preg_split("/\n{2,}/", $text);

        // Wrap <p> tags.
        foreach ($paragraphs as &$chunk) {
            if (empty($this->htmlBlocks[$chunk])) {//unless (defined( $g_html_blocks{$_} )) {
                $chunk = $this->processSpans($chunk);
                $chunk = preg_replace('/^([ \t]*)/', '<p>', $chunk); //s/^([ \t]*)/<p>/;
                $chunk .= "</p>";
            }
        }

        // Unhashify HTML blocks
        foreach ($paragraphs as &$chunk) {
            if (!empty($this->htmlBlocks[$chunk])) {
                $chunk = $this->htmlBlocks[$chunk];
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * These are all the transformations that occur *within* block-level tags
     *
     * @param string $text
     * @return string
     */
    protected function processSpans($text)
    {
        $text = $this->processCodeSpans($text);
        $text = $this->escapeSpecialChars($text);

        /*
         * Process anchor and image tags. Images must come first, because
         * ![foo][f] looks like an anchor.
         */
        $text = $this->processImages($text);
        $text = $this->processAnchors($text);

        # Make links out of things like `<http://example.com/>`
        # Must come after _DoAnchors(), because you can use < and >
        # delimiters in inline links like [this](<url>).
        $text = $this->processAutoLinks($text);

        $text = htmlspecialchars($text);

        $text = $this->processItalicsAndBold($text);

        # Do hard breaks:
        $text = preg_replace("/ {2,}\n/", "<br" . $this->emptyElementSuffix, $text); //=~ s/ {2,}\n/ <br$g_empty_element_suffix\n/g;

        return $text;
    }

    /**
     * Form HTML ordered (numbered) and unordered (bulleted) lists.
     *
     * @param string $text
     * @return string
     */
    protected function processLists($text)
    {
        $lessThanTab = $this->tabLength - 1;

        // Re-usable patterns to match list item bullets and number markers:
        $markerUl = "/[*+-]/";
        $markerOl = "/\d+[.]/";
        $markerAny = "/(?:$markerUl|$markerOl)/";

        // Re-usable pattern to match any entirel ul or ol list:
        $wholeList = "(([ ]{0," . $lessThanTab . "}(" . $markerAny .
            ")[ \t]+)(?s:.+?)\(\z|\n{2,}(?=\S)\(?![ \t]*" . $markerAny .
            "[ \t]+)))";

        /*
         * We use a different prefix before nested lists than top-level lists.
         * See extended comment in processListItems().
         *
         * Note: There's a bit of duplication here. My original implementation
         * created a scalar regex pattern as the conditional result of the test
         * on $listLevel, and then only ran the $text =~ s{...}{...}egmx
         * substitution once, using the scalar as the pattern. This worked,
         * everywhere except when running under MT on my hosting account at Pair
         * Networks. There, this caused all rebuilds to be killed by the reaper
         * (or perhaps they crashed, but that seems incredibly unlikely given
         * that the same script on the same server ran fine *except* under MT.
         * I've spent more time trying to figure out why this is happening than
         * I'd like to admit. My only guess, backed up by the fact that this
         * workaround works, is that Perl optimizes the substition when it can
         * figure out that the pattern will never change, and when this
         * optimization isn't on, we run afoul of the reaper. Thus, the slightly
         * redundant code to that uses two static s/// patterns rather than one
         * conditional pattern.
         */
        if (self::$listLevel) {
            $text = preg_replace_callback("/^" . $wholeList . '/',
                array($this, 'processList'), $text);
        } else {
            $text = preg_replace_callback("/(?:(?<=\n\n)|\A\n?)" . $wholeList .
                '/', array($this, 'processList'), $text);
        }
        return $text;
    }

    /**
     * Callback function to process an individual list (ordered or unordered)
     *
     * @param array $matches
     * @return string
     */
    protected function processList($matches)
    {
        $list = $matches[1];
        $listType = (preg_match("/[*+-]/", $matches[3])) ? "ul" : "ol";
        // Turn double returns into triple returns, so that we can make a
        // paragraph for the last item in a list, if necessary:
        $list = preg_replace("/\n{2,}/", "\n\n\n", $list);
        $result = $this->processListItems($list);
        return "<$listType>\n" . $result . "</$listType>\n";
    }

    /**
     * Process the contents of a single ordered or unordered list, splitting it
     * into individual list items.
     *
     * @param string $list
     * @param string $markerAny Regex pattern to match any type of list
     * @return string
     */
    protected function processListItems($list)
    {
        # The $g_list_level global keeps track of when we're inside a list.
        # Each time we enter a list, we increment it; when we leave a list,
        # we decrement. If it's zero, we're not in a list anymore.
        #
        # We do this because when we're not inside a list, we want to treat
        # something like this:
        #
        #        I recommend upgrading to version
        #        8. Oops, now this line is treated
        #        as a sub-list.
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

        self::$listLevel++;

        # trim trailing blank lines:
        $list = rtrim($list, "\n"); //=~ s/\n{2,}\z/\n/;

        $markerAny = "/(?:[*+-]|\d+[.])/";
        $list = preg_replace_callback("/(\n)?(^[ \t]*)(" . $markerAny .
            ") [ \t]+((?s:.+?)(\n{1,2}))(?= \n* (\z | \2 (" . $markerAny .
            ") [ \t]+))/mx",
            array($this, 'processListItem'), $list);
       /* $list =~ s{
            (\n)?                            # leading line = $1
            (^[ \t]*)                        # leading whitespace = $2
            ($marker_any) [ \t]+            # list marker = $3
            ((?s:.+?)                        # list item text   = $4
            (\n{1,2}))
            (?= \n* (\z | \2 ($marker_any) [ \t]+))
        }{
            my $item = $4;
            my $leading_line = $1;
            my $leading_space = $2;

            if ($leading_line or ($item =~ m/\n{2,}/)) {
                $item = _RunBlockGamut($this->outdent($item));
            }
            else {
                # Recursion for sub-lists:
                $item = _DoLists($this->outdent($item));
                chomp $item;
                $item = _RunSpanGamut($item);
            }

            "<li>" . $item . "</li>\n";
        }egmx;
*/
        self::$listLevel--;
        return $list;
    }

    /**
     * Callback function to process an individual list item
     *
     * @param array $matches
     * @return string
     */
    protected function processListItem($matches)
    {
        $item = $matches[4];
        $leadingLine = $matches[1];
        //$leadingSpace = $matches[2]; //not used?

        if ($leadingLine || preg_match("/\n{2,}/", $item)) {
            $item = $this->processBlocks($this->outdent($item));
        } else {
            // Recursion for sub-lists:
            $item = $this->processLists($this->outdent($item));
            $item = trim($item);
            $item = $this->processSpans($item);
        }

        return "<li>" . $item . "</li>\n";
    }

    protected function processBlockQuotes($text)
    {
        $text = preg_replace_callback("/((^[ \t]*>[ \t]?.+\n(.+\n)*\n*)+)/",
            array($this, 'processBlockQuote'), $text);
        return $text;
    }

    protected function processBlockQuote($matches)
    {
        $bq = $matches[1];
        //=~ s/^[ \t]*>[ \t]?//gm;    # trim one level of quoting
        $bq = preg_replace("/^[ \t]*>[ \t]?/", '', $bq);
        //=~ s/^[ \t]+$//mg;            # trim whitespace-only lines
        $bq = preg_replace("/^[ \t]+$/", '', $bq);
        $bq = $this->processBlocks($bq);   # recurse

        $bq = preg_replace('/^/', '  ', $bq); //=~ s/^/  /g;
        // These leading spaces screw with <pre> content, so we need to fix that:
        $bq = preg_replace_callback('/(\s*<pre>.+?</pre>)/',
            create_function('matches', 'return preg_replace("/^  /", "", $matches[1]);'),
            $bq);

        return "<blockquote>\n$bq\n</blockquote>\n\n";
    }

    protected function processAutoLinks($text)
    {
        // =~ s{<((https?|ftp):[^'">\s]+)>}{<a href="$1">$1</a>}gi;
        $text = preg_replace("/<((https?|ftp):[^'\">\s]+)>/i",
            '<a href="$1">$1</a>', $text);

        // Email addresses: <address@domain.foo>
        $text = preg_replace_callback("/(?:mailto:)?([-.\w]+\\@\[-a-z0-9]+(\.[-a-z0-9]+)*\.[a-z]+)>",
            create_function('matches', 'return $this->encodeEmailAddress($this->unescapeSpecialChars($matches[1]));'), $text);
        return $text;
    }

    #
    # Turn Markdown link shortcuts into XHTML <a> tags.
    #
    protected function processAnchors($text)
    {
        //TODO: fix this g_nested_brackets business

        // First, handle reference-style links: [link text] [id]
        $text = preg_replace_callback("/(\[($g_nested_brackets)\][ ]?(?:\n[ ]*)?\[(.*?)\])/", array($this, 'processPageJumpLink'), $text);

        // Next, inline-style links: [link text](url "optional title")
        $text = preg_replace_callback("(\[($g_nested_brackets)\]\([ \t]*< ?(.*?)>?[ \t]*((['\"])(.*?)\5\)?\))", array($this, 'processAnchor'), $text);
        /*$text =~ s{
            (                # wrap whole match in $1
              \[
                ($g_nested_brackets)    # link text = $2
              \]
              \(            # literal paren
                  [ \t]*
                < ?(.*?)>?    # href = $3
                  [ \t]*
                (            # $4
                  (['"])    # quote char = $5
                  (.*?)        # Title = $6
                  \5        # matching quote
                )?            # title is optional
              \)
            )
        }{
            my $result;
            my $whole_match = $1;
            my $link_text   = $2;
            my $url              = $3;
            my $title        = $6;

            $url =~ s! \* !$g_escape_table{'*'}!gx;        # We've got to encode these to avoid
            $url =~ s!  _ !$g_escape_table{'_'}!gx;        # conflicting with italics/bold.
            $result = "<a href=\"$url\"";

            if (defined $title) {
                $title =~ s/"/&quot;/g;
                $title =~ s! \* !$g_escape_table{'*'}!gx;
                $title =~ s!  _ !$g_escape_table{'_'}!gx;
                $result .=  " title=\"$title\"";
            }

            $result .= ">$link_text</a>";

            $result;
        }xsge; */

        return $text;
    }

    protected function processPageJumpLink($matches)
    {
        $wholeMatch = $matches[1];
        $linkText   = $matches[2];
        $linkId     = strtolower($matches[3]);

        if ($linkId == '') {
            $linkId = strtolower($linkText); // for shortcut links like [this][]
        }

        $result = '';

        if (!empty($this->urls[$linkId])) {
            $url = $this->urls[$linkId];
            /* replace _ and * to avoid conflicts with em/strong */
            $url = str_replace(array('*', '_'), array(md5('*'), md5('_')), $url);
            $result = '<a href="' . $url . '"';
            if (!empty($this->titles[$linkId])) {
                $title = $this->titles[$linkId];
                $title = str_replace(array('*', '_'), array(md5('*'), md5('_')), $title);
                $result .= ' title="' . $title . '"';
            }
            $result .= '>' . $linkText . '</a>';
        } else {
            $result = $wholeMatch;
        }
        return $result;
    }

    protected function processAnchor($matches)
    {
        $linkText = $matches[2];
        $url      = $matches[3];
        $title    = $matches[6];

        /* replace _ and * to avoid conflicts with em/strong */
        $url = str_replace(array('*', '_'), array(md5('*'), md5('_')), $url);
        $result = '<a href="' . $url . '"';

        if (!empty($title)) {
            $title = str_replace(array('*', '_', '"'), array(md5('*'), md5('_'), '&quot;'), $title);
            $result .=  ' title="' . $title . '"';
        }

        $result .= ">$linkText</a>";

        return $result;
    }

    /**
     * Swap back in all the special characters we've hidden.
     *
     * @param string $text
     * @return string
     */
    protected function unescapeSpecialChars($text)
    {
        $charMap = array();
        foreach ($this->specialChars as $char) {
            $charMap[$char] = ord($char);
        }
        $text = str_replace(array_values($charMap), array_keys($charMap), $text);
        return $text;
    }

    #
    #    Input: an email address, e.g. "foo@example.com"
    #
    #    Output: the email address as a mailto link, with each character
    #        of the address encoded as either a decimal or hex entity, in
    #        the hopes of foiling most address harvesting spam bots. E.g.:
    #
    #      <a href="&#x6D;&#97;&#105;&#108;&#x74;&#111;:&#102;&#111;&#111;&#64;&#101;
    #       x&#x61;&#109;&#x70;&#108;&#x65;&#x2E;&#99;&#111;&#109;">&#102;&#111;&#111;
    #       &#64;&#101;x&#x61;&#109;&#x70;&#108;&#x65;&#x2E;&#99;&#111;&#109;</a>
    #
    #    Based on a filter by Matthew Wickline, posted to the BBEdit-Talk
    #    mailing list: <http://tinyurl.com/yu7ue>
    #
    protected function encodeEmailAddress($address)
    {
        /*srand;
        my @encode = (
            sub { '&#' .                 ord(shift)   . ';' },
            sub { '&#x' . sprintf( "%X", ord(shift) ) . ';' },
            sub {                            shift          },
        );*/

        $address = "mailto:" . $address;
        $addressChars = str_split($address);

        foreach ($addressChars as &$char) {
            if ($char === '@') {
                # this *must* be encoded. I insist.
                //$char = $encode[int rand 1]->($char);
            } else if ($char !== ':') {
                # leave ':' alone (to spot mailto: later)
                $r = rand();
                # roughly 10% raw, 45% hex, 45% dec
                $char = ($r > .9) ? $char : ($r < .45) ? '&#x' . dechex(ord($char)) . ';' : '&#' . ord($char) . ';';
            } else {
                //no change
            }
        }//gex;

        $address = '<a href="' . $address . '">' . $address . '</a>';
        $address = preg_replace('/">.+?:/', '">', $address); //=~ s{">.+?:}{">}; # strip the mailto: from the visible part

        return $address;
    }


    /**
     * Remove one level of line-leading tabs or spaces
     *
     * @param string $text
     * @return string
     */
    protected function outdent($text)
    {
        //$text =~ s/^(\t|[ ]{1,$g_tab_width})//gm;
        $text = preg_replace("/^(\t|[ ]{1," . $this->tabLength . "})/", '', $text, 1);
        return $text;
    }

    protected function storeMatchesInHash(array $matches)
    {
        //ignore the entire match
        unset($matches[0]);
        foreach ($matches as $match) {
            $this->htmlBlocks[md5($match)] = "\n\n" . $match . "\n\n";
        }
    }
}

?>

sub _EscapeSpecialChars {
    my $text = shift;
    my $tokens ||= _TokenizeHTML($text);

    $text = '';   # rebuild $text from the tokens
#     my $in_pre = 0;     # Keep track of when we're inside <pre> or <code> tags.
#     my $tags_to_skip = qr!<(/?)(?:pre|code|kbd|script|math)[\s>]!;

    foreach my $cur_token (@$tokens) {
        if ($cur_token->[0] eq "tag") {
            # Within tags, encode * and _ so they don't conflict
            # with their use in Markdown for italics and strong.
            # We're replacing each such character with its
            # corresponding MD5 checksum value; this is likely
            # overkill, but it should prevent us from colliding
            # with the escape values by accident.
            $cur_token->[1] =~  s! \* !$g_escape_table{'*'}!gx;
            $cur_token->[1] =~  s! _  !$g_escape_table{'_'}!gx;
            $text .= $cur_token->[1];
        } else {
            my $t = $cur_token->[1];
            $t = _EncodeBackslashEscapes($t);
            $text .= $t;
        }
    }
    return $text;
}

#
# Turn Markdown image shortcuts into <img> tags.
#
protected function processImages($text)
{
    // First, handle reference-style labeled images: ![alt text][id]
    $text =~ s{
        (                # wrap whole match in $1
          !\[
            (.*?)        # alt text = $2
          \]

          [ ]?                # one optional space
          (?:\n[ ]*)?        # one optional newline followed by spaces

          \[
            (.*?)        # id = $3
          \]

        )
    }{
        my $result;
        my $whole_match = $1;
        my $alt_text    = $2;
        my $link_id     = lc $3;

        if ($link_id eq "") {
            $link_id = lc $alt_text;     # for shortcut links like ![this][].
        }

        $alt_text =~ s/"/&quot;/g;
        if (defined $g_urls{$link_id}) {
            my $url = $g_urls{$link_id};
            $url =~ s! \* !$g_escape_table{'*'}!gx;        # We've got to encode these to avoid
            $url =~ s!  _ !$g_escape_table{'_'}!gx;        # conflicting with italics/bold.
            $result = "<img src=\"$url\" alt=\"$alt_text\"";
            if (defined $g_titles{$link_id}) {
                my $title = $g_titles{$link_id};
                $title =~ s! \* !$g_escape_table{'*'}!gx;
                $title =~ s!  _ !$g_escape_table{'_'}!gx;
                $result .=  " title=\"$title\"";
            }
            $result .= $g_empty_element_suffix;
        }
        else {
            # If there's no such link ID, leave intact:
            $result = $whole_match;
        }

        $result;
    }xsge;

    #
    # Next, handle inline images:  ![alt text](url "optional title")
    # Don't forget: encode * and _

    $text =~ s{
        (                # wrap whole match in $1
          !\[
            (.*?)        # alt text = $2
          \]
          \(            # literal paren
              [ \t]*
            < ?(\S+?)>?    # src url = $3
              [ \t]*
            (            # $4
              (['"])    # quote char = $5
              (.*?)        # title = $6
              \5        # matching quote
              [ \t]*
            )?            # title is optional
          \)
        )
    }{
        my $result;
        my $whole_match = $1;
        my $alt_text    = $2;
        my $url              = $3;
        my $title        = '';
        if (defined($6)) {
            $title        = $6;
        }

        $alt_text =~ s/"/&quot;/g;
        $title    =~ s/"/&quot;/g;
        $url =~ s! \* !$g_escape_table{'*'}!gx;        # We've got to encode these to avoid
        $url =~ s!  _ !$g_escape_table{'_'}!gx;        # conflicting with italics/bold.
        $result = "<img src=\"$url\" alt=\"$alt_text\"";
        if (defined $title) {
            $title =~ s! \* !$g_escape_table{'*'}!gx;
            $title =~ s!  _ !$g_escape_table{'_'}!gx;
            $result .=  " title=\"$title\"";
        }
        $result .= $g_empty_element_suffix;

        $result;
    }xsge;

    return $text;
}


sub _EncodeBackslashEscapes {
#
#   Parameter:  String.
#   Returns:    The string, with after processing the following backslash
#               escape sequences.
#
    local $_ = shift;

    s! \\\\  !$g_escape_table{'\\'}!gx;        # Must process escaped backslashes first.
    s! \\`   !$g_escape_table{'`'}!gx;
    s! \\\*  !$g_escape_table{'*'}!gx;
    s! \\_   !$g_escape_table{'_'}!gx;
    s! \\\{  !$g_escape_table{'{'}!gx;
    s! \\\}  !$g_escape_table{'}'}!gx;
    s! \\\[  !$g_escape_table{'['}!gx;
    s! \\\]  !$g_escape_table{']'}!gx;
    s! \\\(  !$g_escape_table{'('}!gx;
    s! \\\)  !$g_escape_table{')'}!gx;
    s! \\>   !$g_escape_table{'>'}!gx;
    s! \\\#  !$g_escape_table{'#'}!gx;
    s! \\\+  !$g_escape_table{'+'}!gx;
    s! \\\-  !$g_escape_table{'-'}!gx;
    s! \\\.  !$g_escape_table{'.'}!gx;
    s{ \\!  }{$g_escape_table{'!'}}gx;

    return $_;
}



sub _TokenizeHTML {
#
#   Parameter:  String containing HTML markup.
#   Returns:    Reference to an array of the tokens comprising the input
#               string. Each token is either a tag (possibly with nested,
#               tags contained therein, such as <a href="<MTFoo>">, or a
#               run of text between tags. Each element of the array is a
#               two-element array; the first is either 'tag' or 'text';
#               the second is the actual value.
#
#
#   Derived from the _tokenize() subroutine from Brad Choate's MTRegex plugin.
#       <http://www.bradchoate.com/past/mtregex.php>
#

    my $str = shift;
    my $pos = 0;
    my $len = length $str;
    my @tokens;

    my $depth = 6;
    my $nested_tags = join('|', ('(?:<[a-z/!$](?:[^<>]') x $depth) . (')*>)' x  $depth);
    my $match = qr/(?s: <! ( -- .*? -- \s* )+ > ) |  # comment
                   (?s: <\? .*? \?> ) |              # processing instruction
                   $nested_tags/ix;                   # nested tags

    while ($str =~ m/($match)/g) {
        my $whole_tag = $1;
        my $sec_start = pos $str;
        my $tag_start = $sec_start - length $whole_tag;
        if ($pos < $tag_start) {
            push @tokens, ['text', substr($str, $pos, $tag_start - $pos)];
        }
        push @tokens, ['tag', $whole_tag];
        $pos = pos $str;
    }
    push @tokens, ['text', substr($str, $pos, $len - $pos)] if $pos < $len;
    \@tokens;
}

*/