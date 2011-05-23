<?php
/**
 * Markdown  -  A text-to-HTML conversion tool for web writers
 *
 * PHP Markdown
 * @copyright Copyright (c) 2004-2009 Michel Fortin
 * @see http://michelf.com/projects/php-markdown/
 *
 * Original Markdown
 * @copyright Copyright (c) 2004-2006 John Gruber
 * @see http://daringfireball.net/projects/markdown/
 */
namespace Markdown;

const MARKDOWN_VERSION = '1.0.1n'; // Sat 10 Oct 2009

/**
 * Class to manage conversions between Markdown syntax and HTML
 * 
 * @author Brian Fenton <brian@brianfenton.us>
 */
class Markdown
{
    private $parser = null;

    public function __construct(ParserInterface $parser)
    {
        $this->parser = $parser;
    }

    public function __invoke($text)
    {
        if (!$this->parser instanceof ParserInterface) {
            $this->parser = $this->buildParser();
        }
        return $this->parser->transform($text);
    }

    public function getParser()
    {
        return $this->parser;
    }

    public function setParser(ParserInterface $parser)
    {
        $this->parser = $parser;
    }

    public function buildParser()
    {
        return new Markdown_Parser();
    }
}


/*

PHP Markdown
============

Description
-----------

This is a PHP translation of the original Markdown formatter written in
Perl by John Gruber.

Markdown is a text-to-HTML filter; it translates an easy-to-read /
easy-to-write structured text format into HTML. Markdown's text format
is most similar to that of plain text email, and supports features such
as headers, *emphasis*, code blocks, blockquotes, and links.

Markdown's syntax is designed not as a generic markup language, but
specifically to serve as a front-end to (X)HTML. You can use span-level
HTML tags anywhere in a Markdown document, and you can use block level
HTML tags (like <div> and <table> as well).

For more information about Markdown's syntax, see:

<http://daringfireball.net/projects/markdown/>


Bugs
----

To file bug reports please send email to:

<michel.fortin@michelf.com>

Please include with your report: (1) the example input; (2) the output you
expected; (3) the output Markdown actually produced.


Version History
---------------

See the readme file for detailed release notes for this version.


Copyright and License
---------------------

PHP Markdown
Copyright (c) 2004-2009 Michel Fortin
<http://michelf.com/>
All rights reserved.

Based on Markdown
Copyright (c) 2003-2006 John Gruber
<http://daringfireball.net/>
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are
met:

*	Redistributions of source code must retain the above copyright notice,
	this list of conditions and the following disclaimer.

*	Redistributions in binary form must reproduce the above copyright
	notice, this list of conditions and the following disclaimer in the
	documentation and/or other materials provided with the distribution.

*	Neither the name "Markdown" nor the names of its contributors may
	be used to endorse or promote products derived from this software
	without specific prior written permission.

This software is provided by the copyright holders and contributors "as
is" and any express or implied warranties, including, but not limited
to, the implied warranties of merchantability and fitness for a
particular purpose are disclaimed. In no event shall the copyright owner
or contributors be liable for any direct, indirect, incidental, special,
exemplary, or consequential damages (including, but not limited to,
procurement of substitute goods or services; loss of use, data, or
profits; or business interruption) however caused and on any theory of
liability, whether in contract, strict liability, or tort (including
negligence or otherwise) arising in any way out of the use of this
software, even if advised of the possibility of such damage.

*/
?>