<?php

namespace pragmatic\seo;

use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
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
                $event->rules['pragmatic-seo/general'] = 'pragmatic-seo/default/general';
                $event->rules['pragmatic-seo/options'] = 'pragmatic-seo/default/options';
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
                    $event->navItems[] = [
                        'label' => 'Pragmatic',
                        'url' => 'pragmatic-seo',
                        'icon' => __DIR__ . '/icons/gift.svg',
                        'subnav' => [],
                    ];
                    $groupKey = array_key_last($event->navItems);
                }

                $event->navItems[$groupKey]['subnav']['seo'] = [
                    'label' => 'SEO',
                    'url' => 'pragmatic-seo/general',
                ];
            }
        );
        
    }

}
