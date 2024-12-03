<?php
namespace digitalpulsebe\formieflexmail\integrations\emailmarketing;

use verbb\formie\base\Integration;
use verbb\formie\base\EmailMarketing;
use verbb\formie\elements\Submission;
use verbb\formie\models\IntegrationCollection;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;

use Craft;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;

use GuzzleHttp\Client;

use Throwable;

class Flexmail extends EmailMarketing
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Flexmail');
    }

    // Properties
    // =========================================================================

    public ?string $apiKey = null;
    public ?string $apiUsername = null;

    public array $defaultFields = [
        'email' => ['handle' => 'email', 'name' => 'Email', 'required' => true],
        'first_name' => ['handle' => 'first_name', 'name' => 'First Name', 'required' => false],
        'name' => ['handle' => 'name', 'name' => 'Last Name', 'required' => false],
    ];


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Sign up users to your Flexmail');
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['apiKey'], 'required'];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        $handle = 'flexmail';

        return Craft::$app->getView()->renderTemplate("formie-flexmail/integrations/email-marketing/{$handle}/_plugin-settings", [
            'integration' => $this,
        ]);
    }

    public function getFormSettingsHtml($form): string
    {
        $handle = 'flexmail';

        return Craft::$app->getView()->renderTemplate("formie-flexmail/integrations/email-marketing/{$handle}/_form-settings", [
            'integration' => $this,
            'form' => $form,
        ]);
    }

    public function getIconUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl("@digitalpulsebe/formieflexmail/assets/flexmail.svg", true);
    }

    public function fetchFormSettings(): IntegrationFormSettings
    {
        $settings = [];

        try {
            // default fields
            $fields = [];
            foreach ($this->defaultFields as $defaultField) {
                $fields[] = new IntegrationField([
                    'handle' => $defaultField['handle'],
                    'name' => Craft::t('formie', $defaultField['name']),
                    'required' => $defaultField['required'],
                ]);
            }

            // custom fields
            $customFields = $this->getPaginated('custom-fields');
            $fields = array_merge($fields, array_map(function ($field) {
                return new IntegrationField([
                    'handle' => $field['placeholder'],
                    'name' => $field['name'].' ('.$field['type'].')',
                    'required' => false,
                ]);
            }, $customFields));

            // interests
            $interests = $this->getPaginated('interests');
            $fields[] = new IntegrationField([
                'handle' => 'interests',
                'name' => Craft::t('formie', 'Interests'),
                'options' => [
                    'label' => 'Flexmail contact interests',
                    'options' => array_map( function ($interest){
                        return ['value' => $interest['name'], 'label' => $interest['name']];
                        }, $interests)
                ]
            ]);

            // language
            $accountLanguages = $this->request('GET', 'account-contact-languages');
            $languages = $accountLanguages['languages'] ?? [];
            $fields[] = new IntegrationField([
                'handle' => 'language',
                'name' => Craft::t('formie', 'Language'),
                'required' => true,
                'options' => [
                    'label' => 'Flexmail contact languages',
                    'options' => array_map( function ($language){
                        return ['value' => $language, 'label' => $language];
                        }, $languages)
                ]
            ]);

            $sources = $this->getPaginated('sources');
            foreach ($sources as $source) {
                $settings['lists'][] = new IntegrationCollection([
                    'id' => $source['id'],
                    'name' => $source['name'],
                    'fields' => $fields,
                ]);
            }

            $optInforms = $this->getPaginated('opt-in-forms');
            foreach ($optInforms as $source) {
                $settings['lists'][] = new IntegrationCollection([
                    'id' => "opt_in_form-".$source['id'],
                    'name' => $source['name'].' (opt-in)',
                    'fields' => $fields,
                ]);
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission, $this->fieldMapping);

            // map interests
            $newInterests = ArrayHelper::remove($fieldValues, 'interests');
            $payload = $this->mapContactData($fieldValues);

            $isOptInForm = isset($payload['opt_in_form_id']);

            if ($isOptInForm) {
                foreach ($payload['custom_fields'] as $key => $value) {
                    if ($value == null) {
                        // the opt-in endpoint does not like extra custom_fields, while the contacts endpoint wants every field
                        unset($payload['custom_fields'][$key]);
                    }
                }
                // create
                $this->deliverPayload($submission, "opt-ins", $payload, 'POST');
            } else {
                $existingContact = $this->getExistingContact($fieldValues['email']);

                if ($existingContact) {
                    // update
                    $contactId = $existingContact['id'];
                    unset($payload['source']);
                    $response = $this->deliverPayload($submission, "contacts/$contactId", $payload, 'PUT');
                } else {
                    // create
                    $response = $this->deliverPayload($submission, "contacts", $payload, 'POST');
                    $contactId = $response['id'] ?? null;
                }

                if ($response === false) {
                    return true;
                }

                if ($newInterests) {
                    $this->subscribeContactToInterests($contactId, $newInterests);
                }
            }

        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    protected function subscribeContactToInterests($contactId, $newInterests): void
    {
        // Process any interests, we need to fetch them first, then add new interests

        // Cleanup and handle multiple values
        $newInterests = array_filter(array_map('trim', explode(',', $newInterests)));

        $currentInterests = $this->getPaginated("contacts/$contactId/interest-subscriptions");
        $currentInterestIds = array_map(function ($interest) { return $interest['interest_id']; }, $currentInterests);
        $availableInterests = collect($this->getPaginated("interests"));

        foreach ($newInterests as $interestName) {
            $interest = $availableInterests->where('name', '=', $interestName)->first();
            if ($interest) {
                $interestId = $interest['id'];
                if (!in_array($interestId, $currentInterestIds)) {
                    $this->request('POST', "contacts/$contactId/interest-subscriptions", ['json' => ['interest_id' => $interestId]]);
                }
            }
        }
    }

    public function fetchConnection(): bool
    {
        try {
            $response = $this->request('GET', '/');
            $status = $response['status'] ?? '';
            $error = $response['detail'] ?? '';
            $user = $response['user'] ?? null;

            if ($status > 200) {
                Integration::error($this, $error, true);
                return false;
            }

            if (!$user) {
                Integration::error($this, 'Unable to find “{user}” in response.', true);
                return false;
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    public function getClient(): Client
    {
        if ($this->_client) {
            return $this->_client;
        }

        $username = str_replace('D-', '', App::parseEnv($this->apiUsername));

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => 'https://api.flexmail.eu/',
            'auth' => [$username, App::parseEnv($this->apiKey)],
        ]);
    }


    // Protected Methods
    // =========================================================================


    protected function getPaginated(string $uri, array $options = [])
    {
        $limit = 250;
        $offset = 0;

        $options['query']['limit'] = $limit;
        $options['query']['offset'] = $offset;
        $response = $this->request('GET', $uri, $options);

        $total = $response['total'] ?? 0;
        $items = $response['_embedded']['item'] ?? [];

        while ($total > ($offset+$limit)) {
            $offset += $limit;
            $options['query']['offset'] = $offset;
            $response = $this->request('GET', $uri, $options);
            $items = array_merge($items, $response['_embedded']['item'] ?? []);
        }

        return array_map(function ($item) {
            if (isset($item['_links'])) {
                unset($item['_links']);
            }
            return $item;
        }, $items);
    }

    protected function mapContactData($fieldValues): array {
        $targetSource = $this->listId;

        if (str_contains($targetSource, 'opt_in_form-')){
            $optInFormId = str_replace('opt_in_form-', '', $targetSource);
            $data = [
                'opt_in_form_id' => intval($optInFormId)
            ];
        } else {
            $data = [
                'source' => intval($targetSource)
            ];
        }

        $data['custom_fields'] = [];

        // default fields
        foreach (array_merge(array_keys($this->defaultFields), ['language']) as $key) {
            $value = ArrayHelper::remove($fieldValues, $key);
            $data[$key] = $value ?? '';
        }

        // custom fields
        $customFields = $this->getPaginated('custom-fields');
        foreach ($customFields as $customField) {
            $key = $customField['placeholder'];
            $value = ArrayHelper::remove($fieldValues, $key);

            if ($value) {
                if ($customField['type'] == 'multiple_choice' && !is_array($value)) {
                    $value = array_filter(array_map('trim', explode(',', $value)));
                } elseif ($customField['type'] == 'numeric') {
                    $value = floatval($value);
                } elseif ($customField['type'] == 'date') {
                    $value = date('Y-m-d', strtotime($value));
                }
            }

            $data['custom_fields'][$key] = $value;
        }

        if (count($data['custom_fields']) == 0) {
            unset($data['custom_fields']);
        }

        return $data;
    }

    protected function getExistingContact(string $email): ?array
    {
        $contacts = $this->getPaginated('contacts', ['query'=>['email'=>$email]]);

        foreach ($contacts as $contact) {
            if ($contact['email'] == $email) {
                return $contact;
            }
        }

        return null;
    }
}
