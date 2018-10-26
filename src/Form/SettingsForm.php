<?php

/**
 * @file
 * Contains SettingsForm.module.
 */

namespace Drupal\paragraphs_report\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * paragraphs_report settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'paragraphs_report_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['paragraphs_report.settings'];
  }

  /**
   * {@inheritdoc}
   * @throws
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $moduleConfig = \Drupal::config('paragraphs_report.settings');

    // get list of content types to report on
    $contentTypes = \Drupal::service('entity.manager')->getStorage('node_type')->loadMultiple();
    $contentTypesList = [];
    foreach ($contentTypes as $contentType) {
      $contentTypesList[$contentType->id()] = $contentType->label();
    }
    $form['content_types'] = [
      '#title' => t('Content Types'),
      '#type' => 'checkboxes',
      '#options' => $contentTypesList,
      '#default_value' => $moduleConfig->get('content_types') ?? []
    ];

    $form['import_rows_per_batch'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Number of nodes per batch'),
      '#size' => 5,
      '#description'   => t('Lower this value if you have a high number of paragraphs per node.'),
      '#default_value' => $moduleConfig->get('import_rows_per_batch') ?? 10
    );

    $form['watch_content'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Watch for content changes and update report data'),
      '#description'   => t('If enabled, any node save/delete will update Paragraphs Report data.'),
      '#default_value' => $moduleConfig->get('watch_content') ?? FALSE
    );

    // @todo add paragraph checks on entity fields?
    //       what would the path be to an entity? just the edit form?
    // get list of entities to report on
    /*
    $entityTypes = \Drupal::entityTypeManager()->getDefinitions();
    $entityTypesList = [];
    foreach ($entityTypes as $machine => $entityType) {
      $entityTypesList[$machine] = $entityType->getLabel();
    }
    $form['entity_types'] = [
      '#title' => t('Entities'),
      '#type' => 'checkboxes',
      '#options' => $entityTypesList,
      '#default_value' => $moduleConfig->get('entity_types')
    ];
    */
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    //    if ($form_state->getValue('example') != 'example') {
    //      $form_state->setErrorByName('example', $this->t('The value is not correct.'));
    //    }
    //    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('paragraphs_report.settings')
      ->set('content_types', $form_state->getValue('content_types'))
      ->set('import_rows_per_batch', $form_state->getValue('import_rows_per_batch'))
      ->set('watch_content', $form_state->getValue('watch_content'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
