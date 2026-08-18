<?php

namespace Grav\Plugin\Shortcodes;

use Grav\Plugin\FileVault\Service\FileVaultService;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

class FileVaultShortcode extends Shortcode
{
    public function init(): void
    {
        $this->shortcode->getHandlers()->add('file-vault', function (ShortcodeInterface $sc): string {
            if (!$this->canRender((string) ($sc->getParameter('access') ?? ''))) {
                return '';
            }

            $this->shortcode->addAssets('css', 'plugin://file-vault/assets/css/file-vault.css');
            $this->shortcode->addAssets('js', ['plugin://file-vault/assets/js/file-vault.js', ['group' => 'bottom']]);
            $catalog = (new FileVaultService())->publicCatalog();
            $category = strtolower(trim((string) ($sc->getParameter('category') ?? $this->getBbCode($sc) ?? '')));
            $categoryId = $this->categoryId($category, (array) ($catalog['category_details'] ?? []));
            $limit = max(0, (int) ($sc->getParameter('limit') ?? 0));
            $view = strtolower((string) ($sc->getParameter('view') ?? $sc->getParameter('layout') ?? 'list'));
            $view = in_array($view, ['list', 'grid'], true) ? $view : 'list';
            $controls = $this->boolParameter($sc, 'controls', true);
            $hero = $this->boolParameter($sc, 'hero', false);

            if ($categoryId !== '') {
                $catalog['items'] = array_values(array_filter(
                    (array) $catalog['items'],
                    static fn(array $item): bool => (string) ($item['category_id'] ?? '') === $categoryId
                ));
            }
            if ($limit > 0) {
                $catalog['items'] = array_slice((array) $catalog['items'], 0, $limit);
            }
            $catalog['count'] = count((array) $catalog['items']);

            return (string) $this->twig->processTemplate('partials/file-vault-browser.html.twig', [
                'file_vault' => $catalog,
                'embedded' => true,
                'embedded_title' => trim((string) ($sc->getParameter('title') ?? '')),
                'show_hero' => $hero,
                'show_controls' => $controls,
                'show_stats' => $this->boolParameter($sc, 'stats', false),
                'default_view' => $view,
                'default_category' => $categoryId,
            ]);
        });

        $this->shortcode->getHandlers()->add('file-download', function (ShortcodeInterface $sc): string {
            if (!$this->canRender((string) ($sc->getParameter('access') ?? ''))) {
                return '';
            }

            $needle = trim((string) ($sc->getParameter('id') ?? $sc->getParameter('filename') ?? $this->getBbCode($sc) ?? ''));
            if ($needle === '') {
                return '';
            }
            $item = (new FileVaultService())->publicItem($needle);
            if (!$item) {
                return '';
            }

            $this->shortcode->addAssets('css', 'plugin://file-vault/assets/css/file-vault.css');
            return (string) $this->twig->processTemplate('shortcodes/file-download.html.twig', [
                'item' => $item,
                'layout' => strtolower((string) ($sc->getParameter('layout') ?? 'button')),
                'label' => trim((string) ($sc->getParameter('label') ?? $sc->getParameter('title') ?? '')),
            ]);
        });
    }

    /** @param list<array<string,mixed>> $categories */
    private function categoryId(string $needle, array $categories): string
    {
        if ($needle === '') {
            return '';
        }
        foreach ($categories as $category) {
            if (strcasecmp((string) ($category['id'] ?? ''), $needle) === 0
                || strcasecmp((string) ($category['name'] ?? ''), $needle) === 0) {
                return (string) $category['id'];
            }
        }
        return '';
    }

    private function boolParameter(ShortcodeInterface $sc, string $name, bool $default): bool
    {
        $value = $sc->getParameter($name);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function canRender(string $permission): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return true;
        }
        $user = $this->grav['user'] ?? null;
        return $user && method_exists($user, 'authorize') && (bool) $user->authorize($permission);
    }
}
