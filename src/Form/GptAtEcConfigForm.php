<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec\Form;

use Drupal\key\KeyRepositoryInterface;
use Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider\GptAtEcProvider;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Client as GuzzleClient;
use Openeuropa\GptAtEcPhpClient\Client;
use Openeuropa\GptAtEcPhpClient\Factory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the GPT@EC AI provider settings.
 *
 * @todo This is called configForm but around settings is used as name?
 *   See links.menu.yml.
 */
class GptAtEcConfigForm extends ConfigFormBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected GuzzleClient $httpClient;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);

    $instance->httpClient = $container->get('http_client');
    $instance->keyRepository = $container->get('key.repository');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [GptAtEcProvider::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'oe_ai_provider_gpt_at_ec_provider_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $api_key = $this->getConfigApiKey();

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API Key'),
      '#required' => TRUE,
      '#default_value' => $api_key,
      '#key_filters' => ['type' => 'authentication'],
    ];

    if ($api_key !== NULL) {
      $form['quota'] = $this->buildQuotaFieldset([], $form_state);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $api_key = $form_state->getValue('api_key');
    if (empty($api_key)) {
      return;
    }

    try {
      $client = $this->getClient($api_key);
      $client->models()->list();
    }
    catch (\Exception $e) {
      $form_state->setErrorByName(
        'api_key',
        $this->t('An error occurred using the provided key. The error is: %message', [
          '%message' => $e->getMessage(),
        ]),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(GptAtEcProvider::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the quota fieldset element.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The fieldset build array.
   */
  protected function buildQuotaFieldset(array $element, FormStateInterface $form_state): array {
    $api_key = $this->getConfigApiKey();

    try {
      $client = $this->getClient($api_key);
      $models = array_map(
        static fn ($model) => $model['id'],
        $client->models()->list()->toArray()['data'] ?? [],
      );
      asort($models);
    }
    catch (\Exception) {
      $models = [];
    }

    if (empty($models)) {
      $element['#access'] = FALSE;

      return $element;
    }

    $element['quota'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Quota consumption'),
      '#collapsed' => FALSE,
      '#attributes' => [
        'id' => 'gpt-at-ec-provider-quota-wrapper',
      ],
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['value' => $api_key],
        ],
      ],
    ];

    $element['quota']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => $models,
    ];

    $element['quota']['refresh'] = [
      '#type' => 'submit',
      '#name' => 'quota_refresh',
      '#value' => $this->t('Get quota'),
      '#submit' => [[$this, 'updateQuotaConsumption']],
      '#ajax' => [
        'callback' => [static::class, 'updateQuotaConsumptionAjax'],
        'wrapper' => 'gpt-at-ec-provider-quota-wrapper',
      ],
    ];

    $quota = $form_state->get('quota');
    if ($quota) {
      $element['quota']['results'] = [
        '#type' => 'inline_template',
        '#template' => '
            <p>
              <strong>{{ "Consumed prompt tokens"|t }}</strong>: {{ consumed_prompt_tokens }}<br />
              <strong>{{ "Consumed completion tokens"|t }}</strong>: {{ consumed_completion_tokens }}<br />
              <strong>{{ "Consumed total tokens"|t }}</strong>: {{ consumed_total_tokens }}<br />
              <strong>{{ "Quota"|t }}</strong>: {{ quota }}
            </p>
          ',
        '#context' => $quota,
      ];
      // Consume the quota from the state.
      $form_state->set('quota', NULL);
    }

    return $element;
  }

  /**
   * Updates the quota consumption for the currently selected model.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function updateQuotaConsumption(array $form, FormStateInterface $form_state): void {
    try {
      $client = $this->getClient($this->getConfigApiKey());
      $usage = $client->quotaConsumption()->retrieve($form_state->getValue(['quota', 'model']))->toArray();
      $form_state->set('quota', $usage);
    }
    catch (\Exception $e) {
      $form_state->setErrorByName(
        'quota',
        $this->t('An error occurred when retrieving quota consumption. The error is: %message', [
          '%message' => $e->getMessage(),
        ])
      );
    }

    $form_state->setRebuild();
  }

  /**
   * AJAX callback to return the updated quota form element.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element to update.
   */
  public static function updateQuotaConsumptionAjax(array $form, FormStateInterface $form_state): array {
    return $form['quota'];
  }

  /**
   * Returns the currently set API key name.
   *
   * @return string|null
   *   A string if a value is set, NULL otherwise.
   */
  protected function getConfigApiKey(): ?string {
    return $this->config(GptAtEcProvider::CONFIG_NAME)->get('api_key');
  }

  /**
   * Creates an instance of the GPT@EC client.
   *
   * @param string $api_key_id
   *   The API key name.
   *
   * @return \Openeuropa\GptAtEcPhpClient\Client
   *   The client.
   */
  protected function getClient(string $api_key_id): Client {
    $key = $this->keyRepository->getKey($api_key_id);
    return (new Factory())
      ->withApiKey($key->getKeyValue())
      ->withHttpClient($this->httpClient)
      ->make();
  }

}
