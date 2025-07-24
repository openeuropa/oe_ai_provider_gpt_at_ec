<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec\Form;

use Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider\GptAtEcProvider;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the GPT@EC AI provider settings.
 *
 * @todo This is called configForm but around settings is used as name?
 *   See links.menu.yml.
 */
class GptAtEcConfigForm extends ConfigFormBase {

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
    $config = $this->config(GptAtEcProvider::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API Key'),
      '#required' => TRUE,
      '#default_value' => $config->get('api_key'),
      '#key_filters' => ['type' => 'authentication'],
    ];

    return parent::buildForm($form, $form_state);
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

}
