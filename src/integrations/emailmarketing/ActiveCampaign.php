<?php
namespace verbb\formie\integrations\emailmarketing;

use verbb\formie\base\Integration;
use verbb\formie\base\EmailMarketing;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\EmailMarketingList;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class ActiveCampaign extends EmailMarketing
{
    // Properties
    // =========================================================================

    public $apiKey;
    public $apiUrl;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'ActiveCampaign');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Sign up users to your ActiveCampaign lists to grow your audience for campaigns.');
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['apiKey', 'apiUrl'], 'required'];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function fetchFormSettings()
    {
        $settings = [];

        try {
            $response = $this->request('GET', 'lists', [
                'query' => [
                    'limit' => 100,
                ],
            ]);

            $lists = $response['lists'] ?? [];

            foreach ($lists as $list) {
                // While we're at it, fetch the fields for the list
                $response = $this->request('GET', 'fields', [
                    'query' => [
                        'limit' => 100,
                    ],
                ]);

                $fields = $response['fields'] ?? [];

                $listFields = [
                    new IntegrationField([
                        'handle' => 'email',
                        'name' => Craft::t('formie', 'Email'),
                        'required' => true,
                    ]),
                    new IntegrationField([
                        'handle' => 'firstName',
                        'name' => Craft::t('formie', 'First Name'),
                    ]),
                    new IntegrationField([
                        'handle' => 'lastName',
                        'name' => Craft::t('formie', 'Last Name'),
                    ]),
                    new IntegrationField([
                        'handle' => 'phone',
                        'name' => Craft::t('formie', 'Phone'),
                    ]),
                ];

                // Don't use all fields, at least for the moment...
                $supportedFields = [
                    'text',
                    'textarea',
                    'hidden',
                    'dropdown',
                    'radio',
                    'date',
                    // 'checkbox',
                    // 'listbox',
                ];

                foreach ($fields as $field) {
                    if (in_array($field['type'], $supportedFields)) {
                        $listFields[] = new IntegrationField([
                            'handle' => $field['id'],
                            'name' => $field['title'],
                            'type' => $field['type'],
                        ]);
                    }
                }

                $settings['lists'][] = new EmailMarketingList([
                    'id' => $list['id'],
                    'name' => $list['name'],
                    'fields' => $listFields,
                ]);
            }
            
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);
        }

        return $settings;
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission, $this->fieldMapping);

            // Pull out email, as it needs to be top level
            $email = ArrayHelper::remove($fieldValues, 'email');
            $firstName = ArrayHelper::remove($fieldValues, 'firstName');
            $lastName = ArrayHelper::remove($fieldValues, 'lastName');
            $phone = ArrayHelper::remove($fieldValues, 'phone');

            $payload = [
                'contact' => [
                    'email' => $email,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'phone' => $phone,
                    'fieldValues' => $this->_prepCustomFields($fieldValues),
                ],
            ];

            $response = $this->deliverPayload($submission, 'contact/sync', $payload);

            if ($response === false) {
                return false;
            }

            $contactId = $response['contact']['id'] ?? '';

            if (!$contactId) {
                Integration::error($this, Craft::t('formie', 'Missing return “contactId” {response}', [
                    'response' => Json::encode($response),
                ]), true);

                return false;
            }

            $payload = [
                'contactList' => [
                    'list' => $this->listId,
                    'contact' => $contactId,
                    'status' => 1,
                ],
            ];

            $response = $this->deliverPayload($submission, 'contactLists', $payload);

            if ($response === false) {
                return false;
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $clientId = $this->settings['clientId'] ?? '';

            $response = $this->request('GET', 'lists');
            $error = $response['error'] ?? '';
            $lists = $response['lists'] ?? '';

            if ($error) {
                Integration::error($this, $error, true);
                return false;
            }

            if (!$lists) {
                Integration::error($this, 'Unable to find “{lists}” in response.', true);
                return false;
            }

        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => trim($this->apiUrl, '/') . '/api/3/',
            'headers' => ['Api-Token' => $this->apiKey],
        ]);
    }


    // Private Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    private function _prepCustomFields($fields)
    {
        $customFields = [];

        foreach ($fields as $key => $value) {
            $customFields[] = [
                'field' => $key,
                'value' => $value,
            ];
        }

        return $customFields;
    }
}