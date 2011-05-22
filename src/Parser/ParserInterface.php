<?php
namespace Markdown\Parser;

/**
 * Markdown Parser interface
 * @author brianfenton
 */
interface ParserInterface
{
    function transform($text);
}
