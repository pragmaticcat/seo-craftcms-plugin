<?php

namespace pragmatic\seo;

use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
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
    }

    public function getCpNavItem(): array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Pragmatic';
        $item['subnav'] = [
            'seo' => [
                'label' => 'SEO',
                'url' => 'pragmatic-seo/general',
            ],
        ];

        return $item;
    }
}
