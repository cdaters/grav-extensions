<?php

declare(strict_types=1);

namespace Grav\Plugin\Shortcodes;

use Grav\Plugin\SiteWorkshop\Service\IconBenchService;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

final class WorkshopIconShortcode extends Shortcode
{
    public function init(): void
    {
        $this->shortcode->getHandlers()->add('workshop-icon', function (ShortcodeInterface $shortcode): string {
            $reference = trim((string) ($shortcode->getParameter('icon') ?? $shortcode->getParameter('name') ?? $this->getBbCode($shortcode) ?? ''));
            $pack = trim((string) ($shortcode->getParameter('pack') ?? ''));
            if ($pack !== '' && !str_contains($reference, '/')) {
                $reference = $pack . '/' . $reference;
            }
            if ($reference === '') {
                return '';
            }

            return (new IconBenchService())->renderReference($reference, [
                'class' => (string) ($shortcode->getParameter('class') ?? ''),
                'title' => (string) ($shortcode->getParameter('title') ?? ''),
                'size' => (string) ($shortcode->getParameter('size') ?? '1em'),
            ]);
        });
    }
}
