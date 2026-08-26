<?php

namespace Grav\Plugin\FlexibleMarkdownAlerts;

use Grav\Common\Grav;
use Grav\Common\Markdown\BlockResult;
use Grav\Common\Markdown\Element;
use Grav\Common\Markdown\Extension\AbstractMarkdownExtension;
use Grav\Common\Markdown\Extension\BlockContinuableInterface;
use Grav\Common\Markdown\Extension\BlockHandlerInterface;
use Grav\Common\Markdown\Extension\MarkdownExtensionRegistry;
use Grav\Common\Twig\Extension\GravExtension;

/**
 * Configurable Markdown alerts for Grav 2.
 *
 * Recognises `> [!TYPE]` and `> [!TYPE|Custom title]` when TYPE matches an
 * enabled alert definition. The definition owns the default title, icon, and
 * presentation variables; a custom title changes only that rendered instance.
 */
class AlertsExtension extends AbstractMarkdownExtension implements BlockHandlerInterface, BlockContinuableInterface
{
    public function getName(): string
    {
        return 'flexible-alerts';
    }

    public function register(MarkdownExtensionRegistry $registry): void
    {
        // Alerts share the blockquote marker. Index 0 lets this extension
        // recognise configured alert markers before Grav's blockquote handler.
        $registry->registerBlock('FlexibleAlerts', '>', $this, ['index' => 0]);
    }

    /**
     * Open a configured alert block.
     */
    public function block(array $line, ?array $block = null): ?array
    {
        if (!preg_match('/^>\s\[!([A-Z][A-Z0-9-]{0,31})(?:\|([^\]\r\n]+))?\]\s*$/iu', $line['text'], $matches)) {
            return null;
        }

        $type = strtolower($matches[1]);
        $definition = $this->alertDefinitions()[$type] ?? null;
        if ($definition === null) {
            return null;
        }

        $customTitle = isset($matches[2]) ? trim($matches[2]) : null;

        $title = Element::create('p')
            ->attr('class', (string) $this->setting('title_class', 'md-alert-title'))
            ->setInlineText($this->titleText($definition, $customTitle));

        $body = Element::div()
            ->attr('class', (string) $this->setting('body_class', 'md-alert-body'))
            ->setRawLines([]);

        $borderColor = $this->safeColor($definition['border_color'] ?? null, '#333333');
        $titleColor = $this->safeColor($definition['title_color'] ?? null, $borderColor);

        $wrapper = Element::div()
            ->attr('class', (string) $this->setting('wrapper_class', 'md-alert md-alert--') . $type)
            ->attr('dir', 'auto')
            ->attr('style', "--flex-alert-border-color: {$borderColor}; --flex-alert-title-color: {$titleColor};")
            ->setChildren([$title, $body]);

        return BlockResult::fromElement($wrapper)
            ->with(['alert' => true, 'type' => $type])
            ->toArray();
    }

    /**
     * Append quoted lines to the alert body.
     */
    public function blockContinue(array $line, array $block): ?array
    {
        if (isset($block['interrupted']) || empty($block['alert'])) {
            return null;
        }

        $text = preg_replace('/^>\s?/', '', $line['text'] ?? '');
        $block['element']['text'][1]['text'][] = $text;

        return $block;
    }

    /**
     * Build the visible title while retaining the configured type's icon.
     *
     * @param array<string,mixed> $definition
     */
    private function titleText(array $definition, ?string $customTitle = null): string
    {
        $title = $customTitle !== null && $customTitle !== ''
            ? $customTitle
            : (string) $definition['title'];

        if (!(bool) $this->setting('enable_icons', true)) {
            return $title;
        }

        $icon = strtolower(trim((string) ($definition['icon'] ?? '')));
        if ($icon === '' || $icon === 'none') {
            return $title;
        }

        $simpleKey = (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $icon);
        $benchReference = (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,63}\/[a-z0-9][a-z0-9-]{0,63}$/', $icon);
        if (!$simpleKey && !$benchReference) {
            return $title;
        }

        if ($simpleKey) {
            $siteIconUri = 'user://data/flexible-markdown-alerts/icons/icon-' . $icon . '.svg';
            $bundledIconUri = 'plugin://flexible-markdown-alerts/assets/icons/icon-' . $icon . '.svg';
            $locator = Grav::instance()['locator'];

            // A site-owned icon wins over the bundled fallback. This keeps
            // custom assets and overrides outside the upgradeable plugin.
            $iconUri = $locator->findResource($siteIconUri)
                ? $siteIconUri
                : ($locator->findResource($bundledIconUri) ? $bundledIconUri : null);

            if ($iconUri !== null) {
                return GravExtension::svgImageFunction($iconUri) . ' ' . $title;
            }
        }

        // Optional interoperability: when Site Workshop's Icon Bench service
        // is adjacent and enabled, accept its `pack/icon` references. A simple
        // key may also resolve from Icon Bench's default pack after local and
        // bundled lookups fail. No class or package dependency is required.
        $workshopSvg = $this->workshopIcon($icon);

        return $workshopSvg !== '' ? $workshopSvg . ' ' . $title : $title;
    }

    private function workshopIcon(string $reference): string
    {
        $grav = Grav::instance();
        if (!isset($grav['siteWorkshop.icons'])) {
            return '';
        }

        try {
            $service = $grav['siteWorkshop.icons'];
            if (!is_object($service) || !method_exists($service, 'renderReference')) {
                return '';
            }

            $svg = $service->renderReference($reference, [
                'class' => 'md-alert-icon',
                'size' => '16',
            ]);

            return is_string($svg) ? trim($svg) : '';
        } catch (\Throwable) {
            // An optional neighboring plugin must never become a page-render
            // dependency. Fall back to the alert title without an icon.
            return '';
        }
    }

    /**
     * Return enabled, valid definitions indexed by their lowercase type.
     *
     * @return array<string,array<string,mixed>>
     */
    private function alertDefinitions(): array
    {
        $configured = $this->setting('alerts', []);
        if (is_object($configured) && method_exists($configured, 'toArray')) {
            $configured = $configured->toArray();
        }
        if (!is_array($configured)) {
            return [];
        }

        $definitions = [];
        foreach ($configured as $definition) {
            if (is_object($definition) && method_exists($definition, 'toArray')) {
                $definition = $definition->toArray();
            }
            if (!is_array($definition) || (isset($definition['enabled']) && !(bool) $definition['enabled'])) {
                continue;
            }

            $type = strtolower(trim((string) ($definition['type'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $type)) {
                continue;
            }

            $definition['type'] = $type;
            $definition['title'] = trim((string) ($definition['title'] ?? '')) ?: strtoupper($type);
            $definitions[$type] = $definition;
        }

        return $definitions;
    }

    private function safeColor($color, string $fallback): string
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : $fallback;
    }

    /**
     * Read a value from the plugin configuration.
     *
     * @param mixed $default
     * @return mixed
     */
    private function setting(string $key, $default = null)
    {
        $config = $this->getConfig();

        if (is_array($config)) {
            return $config[$key] ?? $default;
        }
        if (is_object($config) && method_exists($config, 'get')) {
            return $config->get($key, $default);
        }

        return $default;
    }
}
