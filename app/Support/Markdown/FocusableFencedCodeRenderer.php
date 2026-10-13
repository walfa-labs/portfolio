<?php

namespace App\Support\Markdown;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

/**
 * Renders a fenced code block as `<pre tabindex="0"><code class="language-…">`.
 *
 * Mirrors `FencedCodeRenderer` from `league/commonmark`, which hardcodes an
 * attribute-free `pre`; only the `tabindex` differs, so the `language-*` class
 * the inner `code` already carried is preserved. See
 * {@see FocusableCodeBlockExtension} for why the attribute is needed.
 */
final class FocusableFencedCodeRenderer implements NodeRendererInterface
{
    /**
     * @param  FencedCode  $node
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        FencedCode::assertInstanceOf($node);

        $attributes = $node->data->getData('attributes');

        $infoWords = $node->getInfoWords();

        if (count($infoWords) !== 0 && $infoWords[0] !== '') {
            $class = $infoWords[0];

            if (! str_starts_with($class, 'language-')) {
                $class = 'language-'.$class;
            }

            $attributes->append('class', $class);
        }

        return new HtmlElement(
            'pre',
            ['tabindex' => '0'],
            new HtmlElement('code', $attributes->export(), Xml::escape($node->getLiteral()))
        );
    }
}
