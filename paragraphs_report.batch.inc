<?php

// Batch API Methods

//use Drupal\paragraphs_report\Controller\ParagraphsReport;
use Drupal\Component\Serialization\Json;
//use Drupal\field\Entity\FieldConfig;

/**
 * Batch method to load node and check for para field content.
 *
 * @param array $nids of node ids to process per batch run
 * @param array $context
 * @throws
 */
function getParaFields($nids, &$context) {
  // Initiate multistep processing and any custom batch vars.
  if (empty($context['sandbox'])) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = count($nids);
  }
  // Counter var.
  if (empty($context['results']['count'])) {
    $context['results']['count'] = 0;
  }
  if(!empty($nids)) {
    foreach($nids as $nid) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
      $alias = $node->toUrl()->toString();
      // Get and loop through first level paragraph fields on the node.
      $paraFields = getParaFieldsOnType('node', $node->bundle());
      foreach($paraFields as $paraField) {
        // Get paragraph values (target_ids).
        $paras = $node->get($paraField)->getValue();
        foreach($paras as $para) {
          // Load paragraph from target_id.
          $p = \Drupal\paragraphs\Entity\Paragraph::load($para['target_id']);
          // Add paragraph to report array
          //$reports[$p->bundle()]['top'][] = $alias;
          $context['results']['paras'][$p->bundle()]['top'][] = $alias;
          // Check if the top level paragraph has sub-paragraph fields.
          //$reports = getParaSubFields($node, $p, $reports);
          $context['results']['paras'] = getParaSubFields($node, $p, $context['results']['paras']);
        }
      }
      $context['results']['count']++;
      $context['sandbox']['progress']++;
    }
    // Multistep processing : report progress.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }
}

function getParaFieldsOnType($bundle = '', $type = '') {
  $entityManager = \Drupal::service('entity_field.manager');
  $paraFields = [];
  $fields = $entityManager->getFieldDefinitions($bundle, $type); // node, hero_cta
  foreach($fields as $field_name => $field_definition) {
    if (!empty($field_definition->getTargetBundle()) && $field_definition->getSetting('target_type') == 'paragraph') {
      $paraFields[] = $field_name;
    }
  }
  return $paraFields;
}

/**
 * Helper recursive method to find embedded paragraphs
 * Send a paragraph, check fields for sub-paragraph fields recursively.
 *
 * @return array of paragraph values
 */
function getParaSubFields($node, $paragraph, $reports) {
//  $alias = $node->toUrl()->toString();
//  // Check for paragraph type fields
//  $paraFields = getParaFieldsOnType('paragraph', $paragraph->bundle());
//  foreach($paraFields as $paraField) {
//    // Get paragraph values (target_ids).
//    $paras = $node->get($paraField)->getValue();
//    foreach ($paras as $para) {
//      $p = \Drupal\paragraphs\Entity\Paragraph::load($para['target_id']);
//      $reports[$p->bundle()][$paragraph->bundle()][] = $alias;
//      $reports = getParaSubFields($node, $p, $reports);
//    }
//  }
//  return $reports;

  $alias = $node->toUrl()->toString();
  $entityManager = \Drupal::service('entity_field.manager');
  // Get fields on paragraph and check field type.
  $fields = $entityManager->getFieldDefinitions('paragraph', $paragraph->bundle());
  foreach ($fields as $field_name => $field_definition) {
    // Is this field a paragraph type?
    if (!empty($field_definition->getTargetBundle()) && $field_definition->getSetting('target_type') == 'paragraph') {
      // Get paragraphs on this field.
      $paras = $paragraph->get($field_name)->getValue();
      foreach ($paras as $para) {
        $p = \Drupal\paragraphs\Entity\Paragraph::load($para['target_id']);
        // If yes, add this field to report and check for more sub-fields.
        // arr[main component][parent] = alias of node
        $reports[$p->bundle()][$paragraph->bundle()][] = $alias;
        $reports = getParaSubFields($node, $p, $reports);
      }
    }
  }
  return $reports;
}

/**
 * After batch process has completed, save results to db.
 *
 * @param $success
 * @param $results
 * @param $operations
 */
function batchSave($success, $results, $operations) {
  // JSON encode and save to module config.
  $moduleConfig = \Drupal::service('config.factory')->getEditable('paragraphs_report.settings');
  $json = Json::encode($results['paras']);
  $moduleConfig->set('report', $json)->save();
  if ($success) {
    $message = \Drupal::translation()
      ->formatPlural($results['count'], 'One node processed.', '@count nodes processed.');
    \Drupal::logger('my_module')->notice($message);
  }
  else {
    $message = t('Finished with an error.');
    \Drupal::logger('paragraphs_report')->error($message);
  }
  drupal_set_message($message);
}
