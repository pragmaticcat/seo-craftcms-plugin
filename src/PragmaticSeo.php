<?php

namespace pragmatic\seo;

use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use pragmatic\seo\fields\SeoField;
use yii\base\Event;

class PragmaticSeo extends Plugin
{
    public bool $hasCpSection = true;
    public string $templateRoot = 'src/templates';

    public function init(): void
    {
        parent::init();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pragmatic-seo'] = 'pragmatic-seo/default/index';
                $event->rules['pragmatic-seo/images'] = 'pragmatic-seo/default/images';
                $event->rules['pragmatic-seo/options'] = 'pragmatic-seo/default/options';
                $event->rules['pragmatic-seo/content'] = 'pragmatic-seo/default/content';
                $event->rules['pragmatic-seo/sitemap'] = 'pragmatic-seo/default/sitemap';
                $event->rules['pragmatic-seo/sitemap/save'] = 'pragmatic-seo/default/save-sitemap';
                $event->rules['pragmatic-seo/general'] = 'pragmatic-seo/default/general';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['sitemap.xml'] = 'pragmatic-seo/default/sitemap-xml';
            }
        );

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SeoField::class;
            }
        );

        // Register nav item under shared "Pragmatic" group
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $groupKey = null;
                foreach ($event->navItems as $key => $item) {
                    if (($item['label'] ?? '') === 'Pragmatic' && isset($item['subnav'])) {
                        $groupKey = $key;
                        break;
                    }
                }

                if ($groupKey === null) {
                    $newItem = [
                        'label' => 'Pragmatic',
                        'url' => 'pragmatic-seo/content',
                        'icon' => __DIR__ . '/icons/icon.svg',
                        'subnav' => [],
                    ];

                    // Insert after the first matching nav item
                    $afterKey = null;
                    $insertAfter = ['users', 'assets', 'categories', 'entries'];
                    foreach ($insertAfter as $target) {
                        foreach ($event->navItems as $key => $item) {
                            if (($item['url'] ?? '') === $target) {
                                $afterKey = $key;
                                break 2;
                            }
                        }
                    }

                    if ($afterKey !== null) {
                        $pos = array_search($afterKey, array_keys($event->navItems)) + 1;
                        $event->navItems = array_merge(
                            array_slice($event->navItems, 0, $pos, true),
                            ['pragmatic' => $newItem],
                            array_slice($event->navItems, $pos, null, true),
                        );
                        $groupKey = 'pragmatic';
                    } else {
                        $event->navItems['pragmatic'] = $newItem;
                        $groupKey = 'pragmatic';
                    }
                }

                $event->navItems[$groupKey]['subnav']['seo'] = [
                    'label' => 'SEO',
                    'url' => 'pragmatic-seo/content',
                ];
            }
        );
        
    }

    public function getCpNavItem(): ?array
    {
        return null;
    }

}
