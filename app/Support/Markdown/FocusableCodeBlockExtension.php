<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Renders code blocks as keyboard-reachable scroll containers.
 *
 * `.writing-body pre` carries `overflow-x: auto` so a wide code block scrolls
 * instead of wrapping mid-token. A scroll container with no focusable child
 * cannot be scrolled by keyboard alone, which is a WCAG 2.1.1 failure and the
 * axe `scrollable-region-focusable` violation (§7.2). The `tabindex="0"` the
 * rule asks for belongs on the `pre` itself, and CommonMark's own renderers
 * hardcode an attribute-free `pre` — hence the two overrides.
 *
 * `role="region"` is deliberately not added: a region needs an accessible name
 * the block does not have, and axe is satisfied by the focusable scroll
 * container alone.
 *
 * The attribute is emitted here rather than in `WritingShow.vue` because the
 * body reaches the page as `body_html` and `v-html` cannot attach attributes.
 *
 * `CommonMarkCoreExtension` registers its own code block renderers at priority
 * 0, and a renderer list is tried in priority order, so these must sit above it
 * to be reached at all.
 */
class FocusableCodeBlockExtension implements ExtensionInterface
{
    /**
     * Priority that puts these renderers ahead of the CommonMark core ones.
     */
    private const RENDERER_PRIORITY = 10;

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(FencedCode::class, new FocusableFencedCodeRenderer, self::RENDERER_PRIORITY);
        $environment->addRenderer(IndentedCode::class, new FocusableIndentedCodeRenderer, self::RENDERER_PRIORITY);
    }
}
