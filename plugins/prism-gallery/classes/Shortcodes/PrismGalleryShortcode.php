<?php

namespace Grav\Plugin\Shortcodes;

use Grav\Common\Utils;
use Grav\Plugin\PrismGallery\Service\ProtectedMediaService;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

class PrismGalleryShortcode extends Shortcode
{
    private static $instance = 0;

    public function init(): void
    {
        $this->registerGallery('prism-gallery');
        $this->registerItem('prism');

        if ((bool) $this->config->get('plugins.prism-gallery.compatibility_aliases', true)) {
            $this->registerGallery('lightbox-gallery');
            $this->registerItem('lightbox');
        }
    }

    private function registerGallery(string $name): void
    {
        $this->shortcode->getHandlers()->add($name, function (ShortcodeInterface $sc): string {
            $this->assets();
            $class = trim('prism-gallery-grid ' . (string) $sc->getParameter('class', ''));
            $label = trim((string) $sc->getParameter('label', 'Media gallery'));
            $min = $this->cssValue((string) $sc->getParameter('min', $this->config->get('plugins.prism-gallery.grid.minimum_width', '220px')), '220px');
            $gap = $this->cssValue((string) $sc->getParameter('gap', $this->config->get('plugins.prism-gallery.grid.gap', '1rem')), '1rem');

            return '<div class="' . self::escAttr($class) . '" role="group" aria-label="' . self::escAttr($label)
                . '" style="--prism-grid-min:' . self::escAttr($min) . ';--prism-grid-gap:' . self::escAttr($gap) . '">'
                . $sc->getContent() . '</div>';
        });
    }

    private function registerItem(string $name): void
    {
        $this->shortcode->getHandlers()->add($name, function (ShortcodeInterface $sc): string {
            $this->assets();

            $image = trim((string) $sc->getParameter('image', ''));
            $video = trim((string) $sc->getParameter('video', ''));
            $iframe = trim((string) $sc->getParameter('iframe', $sc->getParameter('url', '')));
            $source = $image !== '' ? $image : ($video !== '' ? $video : $iframe);
            if ($source === '') {
                return '';
            }

            $type = $image !== '' ? 'image' : ($video !== '' ? 'video' : 'iframe');
            $resolved = $this->resolveMedia($source, $type, false);
            if ($resolved['url'] === '') {
                return '';
            }

            $type = $resolved['type'];
            $sourceUrl = $resolved['url'];
            $resolveUrl = (string) ($resolved['resolve'] ?? '');
            $title = trim((string) $sc->getParameter('title', ''));
            $group = trim((string) $sc->getParameter('gallery', $sc->getParameter('group', '')));
            if ($group === '') {
                $page = $this->grav['page'] ?? null;
                $route = $page && method_exists($page, 'route') ? (string) $page->route() : 'page';
                $group = 'page-' . substr(hash('sha256', $route), 0, 12);
            }

            $content = trim((string) $sc->getContent());
            $thumb = trim((string) $sc->getParameter('thumb', ''));
            $thumbOptions = trim((string) $sc->getParameter('thumb_options', ''));
            $hasTriggerMarkup = $thumb === '' && preg_match('/<(?:img|picture|button|figure|span)\b/i', $content);
            $description = $hasTriggerMarkup ? trim((string) $sc->getParameter('desc', '')) : $content;
            $trigger = $hasTriggerMarkup ? $content : $this->triggerMarkup($thumb, $thumbOptions, $source, $type, $title);

            self::$instance++;
            $descId = $description !== '' ? 'prism-desc-' . self::$instance . '-' . substr(hash('sha256', $description), 0, 8) : '';
            $descriptionHtml = $description !== '' ? Utils::processMarkdown($description, false, $this->grav['page'] ?? null) : '';
            $autoplay = $this->boolParameter($sc, 'autoplay', (bool) $this->config->get('plugins.prism-gallery.autoplay_videos', false));
            $zoom = $this->boolParameter($sc, 'zoomable', (bool) $this->config->get('plugins.prism-gallery.zoom', true));
            $customClass = trim((string) $sc->getParameter('class', ''));
            $label = $title !== '' ? 'Open ' . $title : 'Open media viewer';
            $loop = $this->boolParameter($sc, 'loop', (bool) $this->config->get('plugins.prism-gallery.loop', true));
            $keyboard = $this->boolParameter($sc, 'keyboard', (bool) $this->config->get('plugins.prism-gallery.keyboard', true));
            $touch = $this->boolParameter($sc, 'touch', (bool) $this->config->get('plugins.prism-gallery.touch', true));
            $backdrop = $this->boolParameter($sc, 'close_on_backdrop', (bool) $this->config->get('plugins.prism-gallery.close_on_backdrop', true));
            $animation = $this->animationParameter((string) $sc->getParameter('animation', $this->config->get('plugins.prism-gallery.animation', 'lift')));

            $html = '<a class="prism-trigger ' . self::escAttr($customClass) . '" href="' . self::escAttr($sourceUrl)
                . '" data-prism-type="' . self::escAttr($type) . '" data-prism-src="' . self::escAttr($sourceUrl)
                . '" data-prism-gallery="' . self::escAttr($group) . '" data-prism-title="' . self::escAttr($title)
                . '" data-prism-autoplay="' . ($autoplay ? 'true' : 'false') . '" data-prism-zoom="' . ($zoom ? 'true' : 'false') . '"'
                . ' data-prism-loop="' . ($loop ? 'true' : 'false') . '" data-prism-keyboard="' . ($keyboard ? 'true' : 'false') . '"'
                . ' data-prism-touch="' . ($touch ? 'true' : 'false') . '" data-prism-backdrop="' . ($backdrop ? 'true' : 'false') . '"'
                . ' data-prism-animation="' . self::escAttr($animation) . '"'
                . ($resolveUrl !== '' ? ' data-prism-resolve="' . self::escAttr($resolveUrl) . '"' : '')
                . ($descId !== '' ? ' data-prism-description="#' . self::escAttr($descId) . '"' : '')
                . ' aria-label="' . self::escAttr($label) . '">' . $trigger . '</a>';

            if ($descId !== '') {
                $html .= '<div id="' . self::escAttr($descId) . '" class="prism-description" hidden>' . $descriptionHtml . '</div>';
            }

            return $html;
        });
    }

    private function assets(): void
    {
        $this->shortcode->addAssets('css', 'plugin://prism-gallery/assets/css/prism-gallery.css?v=0.2.1');
        $this->shortcode->addAssets('js', ['plugin://prism-gallery/assets/js/prism-gallery.js?v=0.2.1', ['group' => 'bottom', 'priority' => 85]]);
    }

    private function triggerMarkup(string $thumb, string $thumbOptions, string $source, string $type, string $title): string
    {
        $candidate = $thumb !== '' ? $thumb : ($type === 'image' ? $source : '');
        if ($candidate === '' && $type === 'youtube') {
            $id = $this->youtubeId($source);
            if ($id !== '') {
                $candidate = 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
            }
        }

        if ($candidate !== '') {
            $resolved = $this->resolveMedia($candidate, 'image', true, $thumbOptions);
            if ($resolved['url'] !== '') {
                return '<span class="prism-thumb"><img src="' . self::escAttr($resolved['url']) . '" alt="' . self::escAttr($title)
                    . '" loading="lazy" decoding="async">' . ($type !== 'image' ? '<span class="prism-play" aria-hidden="true"></span>' : '') . '</span>';
            }
        }

        $kind = in_array($type, ['youtube', 'vimeo'], true) ? ucfirst($type) : ($type === 'video' ? 'Video' : 'Media');
        return '<span class="prism-media-card"><span class="prism-play" aria-hidden="true"></span><strong>'
            . self::escAttr($title !== '' ? $title : $kind) . '</strong><small>' . self::escAttr($kind) . '</small></span>';
    }

    /** @return array{url:string,type:string,resolve:string} */
    private function resolveMedia(string $candidate, string $type, bool $thumbnail = false, string $options = ''): array
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return ['url' => '', 'type' => $type, 'resolve' => ''];
        }

        if ($type === 'video') {
            $youtube = $this->youtubeId($candidate);
            if ($youtube !== '') {
                return ['url' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtube) . '?rel=0&modestbranding=1', 'type' => 'youtube', 'resolve' => ''];
            }
            $vimeo = $this->vimeoId($candidate);
            if ($vimeo !== '') {
                return ['url' => 'https://player.vimeo.com/video/' . rawurlencode($vimeo), 'type' => 'vimeo', 'resolve' => ''];
            }
        }

        $parts = explode('?', $candidate, 2);
        $filename = $parts[0];
        $page = $this->grav['page'] ?? null;
        $media = $page && method_exists($page, 'media') ? $page->media() : null;
        $medium = $media ? $media->get($filename) : null;
        if ($medium) {
            if ($thumbnail) {
                $processing = $options !== '' ? $options : ($parts[1] ?? '');
                $medium = $this->processMedium($medium, $processing);
            }
            if (!$thumbnail && (bool) $this->config->get('plugins.prism-gallery.protection.enabled', true)) {
                $resolve = (new ProtectedMediaService())->registerPath((string) $medium->path());
                if ($resolve !== '') {
                    return ['url' => '#prism-viewer', 'type' => $type, 'resolve' => $resolve];
                }
            }
            return ['url' => (string) $medium->url(), 'type' => $type, 'resolve' => ''];
        }

        if (preg_match('#^https://#i', $candidate)) {
            return ['url' => $candidate, 'type' => $type, 'resolve' => ''];
        }
        if (strpos($candidate, '/') === 0 && strpos($candidate, '//') !== 0) {
            return ['url' => $candidate, 'type' => $type, 'resolve' => ''];
        }

        return ['url' => '', 'type' => $type, 'resolve' => ''];
    }

    private function processMedium($medium, string $processing)
    {
        parse_str(str_replace(',', '%2C', $processing), $operations);
        foreach ($operations as $operation => $value) {
            $args = array_map('intval', explode(',', urldecode((string) $value)));
            if ($operation === 'cropZoom' && count($args) >= 2) {
                $medium = $medium->cropZoom(max(1, $args[0]), max(1, $args[1]));
            } elseif ($operation === 'resize' && count($args) >= 2) {
                $medium = $medium->resize(max(1, $args[0]), max(1, $args[1]));
            } elseif ($operation === 'width' && count($args) >= 1) {
                $medium = $medium->width(max(1, $args[0]));
            } elseif ($operation === 'height' && count($args) >= 1) {
                $medium = $medium->height(max(1, $args[0]));
            }
        }
        return $medium;
    }

    private function youtubeId(string $url): string
    {
        if (preg_match('#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})#i', $url, $match)) {
            return $match[1];
        }
        return '';
    }

    private function vimeoId(string $url): string
    {
        if (preg_match('#(?:vimeo\.com/(?:video/)?)((?:\d){5,})#i', $url, $match)) {
            return $match[1];
        }
        return '';
    }

    private function boolParameter(ShortcodeInterface $sc, string $name, bool $default): bool
    {
        $value = $sc->getParameter($name);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function cssValue(string $value, string $fallback): string
    {
        return preg_match('/^[0-9.]+(?:px|rem|em|vw|vh|%)$/', trim($value)) ? trim($value) : $fallback;
    }

    private function animationParameter(string $value): string
    {
        return in_array($value, ['lift', 'fade', 'none'], true) ? $value : 'lift';
    }
}
