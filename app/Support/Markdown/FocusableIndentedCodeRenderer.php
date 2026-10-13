<?php

namespace App\Support\Markdown;

use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

/**
 * Renders an indented code block as `<pre tabindex="0"><code>…</code></pre>`.
 *
 * Mirrors `IndentedCodeRenderer` from `league/commonmark`, which hardcodes an
 * attribute-free `pre`; only the `tabindex` differs. An indented block has no
 * info string, so the inner `code` keeps whatever attributes the node carries.
 * See {@see FocusableCodeBlockExtension} for why the attribute is needed.
 */
final class FocusableIndentedCodeRenderer implements NodeRendererInterface
{
    /**
     * @param  IndentedCode  $node
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        IndentedCode::assertInstanceOf($node);

        return new HtmlElement(
            'pre',
            ['tabindex' => '0'],
            new HtmlElement('code', $node->data->getData('attributes')->export(), Xml::escape($node->getLiteral()))
        );
    }
}
