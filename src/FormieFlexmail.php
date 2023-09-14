<?php

namespace digitalpulsebe\formieflexmail;

use Craft;
use craft\base\Plugin;
use digitalpulsebe\formieflexmail\integrations\emailmarketing\Flexmail;
use verbb\formie\events\RegisterIntegrationsEvent;
use verbb\formie\services\Integrations;
use yii\base\Event;

/**
 * Flexmail for Formie plugin
 *
 * @method static FormieFlexmail getInstance()
 * @author Digital Pulse nv <support@digitalpulse.be>
 * @copyright Digital Pulse nv
 * @license MIT
 */
class FormieFlexmail extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    private function attachEventHandlers(): void
    {
        Event::on(Integrations::class, Integrations::EVENT_REGISTER_INTEGRATIONS, function(RegisterIntegrationsEvent $event) {
            $event->emailMarketing[] = Flexmail::class;
        });
    }
}
