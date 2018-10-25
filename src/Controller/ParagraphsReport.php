<?php
// @todo add node/entity save hooks to add on the fly paragraphs reporting
//       may need to redo how JSON is stored to append/edit current saved
//       entries instead of bulk running entire report.

namespace Drupal\paragraphs_report\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;

// disable notices since core pager throws undefined indexes
//error_reporting(E_ERROR | E_WARNING | E_PARSE);


/**
 * paragraphs_report methods
 */
class ParagraphsReport extends ControllerBase {

  /**
   * Constructs the controller object.
   */
  public function __construct() {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }


  // B A T C H - - - - - - - - - - - - - - - - - - - - - - - - //

  /**
   * Batch API starting point.
   *
   * @todo update logic to check for sub-components (nth level paragraphs)
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * @throws
   */
  public function runReport() {
    // Get all nodes to process.
    $nodes = $this->getNodes();
    // Put nodes into batches.
    $batch = $this->batchPrep($nodes);
    // Start batch api process.
    batch_set($batch);
    // Redirect page and display message on completion.
    return batch_process('/admin/reports/paragraphs-report');
  }

  /**
   * Setup batch array var
   *
   * @return array of batches ready to run
   */
  function batchPrep() {
    $moduleConfig = \Drupal::config('paragraphs_report.settings');
    $nodes = $this->getNodes();
    // Batch vars.
    $totalRows        = count($nodes);
    $rowsPerBatch     = !empty($moduleConfig->get('import_rows_per_batch')) ? $moduleConfig->get('import_rows_per_batch') : 10;
    $batchesPerImport = ceil($totalRows / $rowsPerBatch);
    // Put x-amount of rows into operations array slots.
    $operations = [];
    for($i=0; $i<$batchesPerImport; $i++) {
      $offset = ($i==0) ? 0 : $rowsPerBatch*$i;
      $nids = array_slice($nodes, $offset, $rowsPerBatch);
      $operations[] = ['getParaFields', [$nids]];
    }
    // Full batch array.
    $batch = [
      'init_message' => t('Executing a batch...'),
      'progress_message' => t('Operation @current out of @total batches, @perBatch per batch.',
        ['@perBatch' => $rowsPerBatch]
      ),
      'progressive'   => TRUE,
      'error_message' => t('Batch failed.'),
      'operations' => $operations,
      'finished'   => 'batchSave',
      'file'       => drupal_get_path('module', 'paragraphs_report') . '/paragraphs_report.batch.inc',
    ];
    return $batch;
  }


  // L O O K U P S - - - - - - - - - - - - - - - - - - - - - - - //


  /**
   * Get paragraph fields for selected content types.
   *
   * @return array of paragraph fields by content type key
   */
  public function getParaFieldDefinitions() {
    $entityManager = \Drupal::service('entity_field.manager');
    $moduleConfig = \Drupal::config('paragraphs_report.settings');
    // figure out what content types were chosen in settings
    $contentTypes = array_filter($moduleConfig->get('content_types'));
    // then loop through the fields for chosen content types to get paragraph fields
    $paraFields = []; // content_type[] = field_name
    foreach($contentTypes as $contentType) {
      $fields = $entityManager->getFieldDefinitions('node', $contentType);
      foreach($fields as $field_name => $field_definition) {
        if (!empty($field_definition->getTargetBundle()) && $field_definition->getSetting('target_type') == 'paragraph') {
          $paraFields[$contentType][] = $field_name;
        }
      }
    }
    return $paraFields;
  }

  /**
   * Query db for nodes to check for paragraphs.
   *
   * @return array of nids to check for para fields.
   */
  public function getNodes() {
    $moduleConfig = \Drupal::config('paragraphs_report.settings');
    $contentTypes = array_filter($moduleConfig->get('content_types'));
    // Load all nodes of type
    $query = \Drupal::entityQuery('node')
      ->condition('type', $contentTypes, 'IN');
    $nids = $query->execute();
    return $nids;
  }


  // R E P O R T - - - - - - - - - - - - - - - - - - - - - - - //


  /**
   * Build quick paragraphs type drop down form.
   *
   * @return string
   */
  public function filterForm() {
    // Build filter form.
    // Check and set filters
    $paras = paragraphs_type_get_types();
    $names = [];
    foreach($paras as $machine => $obj) {
      $names[$machine] = $obj->label();
    }
    $current_path = \Drupal::service('path.current')->getPath();
    $filterForm = '<form method="get" action="' . $current_path . '">';
    $filterForm .= 'Filter by Type: <select name="ptype">';
    $filterForm .= '<option value="">All</option>';
    foreach ($names as $machine => $label) {
      $selected = isset($_GET['ptype']) && $_GET['ptype'] == $machine ? ' selected' : '';
      $filterForm .= '<option name="' . $machine . '" value="' . $machine . '"' . $selected . '>' . $label . '</option>';
    }
    $filterForm .= '</select> <input type="submit" value="Go"></form><br>';
    return $filterForm;
  }

  /**
   * Format the stored JSON config var into a rendered table.
   *
   * @param array $json
   * @return array
   */
  public function formatTable($json = []) {
    // get filter
    $filter = isset($_GET['ptype']) ? trim($_GET['ptype']) : '';
    // get paragraphs label info, translate machine name to label
    // loop results into the table
    $total = 0;
    $rows = [];
    if(!empty($json)) {
      foreach($json as $name => $set) {
        // skip if we are filtering out all but one
        if(!empty($filter) && $filter != $name) {
          continue;
        }
        $total++;
        // be mindful of the parent field
        foreach($set as $parent => $paths) {
          // turn duplicates into counts
          $counts = array_count_values($paths);
          foreach($counts as $path => $count) {
            $link = t('<a href="@path">@path</a>',['@path' => $path]);
            $rows[] = [$name, $parent, $link, $count];
          }
        }
      }
    }
    $header = [
      $this->t('Paragraph'),
      $this->t('Parent'),
      $this->t('Path'),
      $this->t('Count')
    ];
    // Setup pager.
    $per_page = 10;
    $current_page = pager_default_initialize($total, $per_page);
    // split array into page sized chunks, if not empty
    $chunks = !empty($rows) ? array_chunk($rows, $per_page, TRUE) : 0;
    // Output
    $table['table'] = [
      '#type' => 'table',
      '#title' => $this->t('Paragraphs Report'),
      '#header' => $header,
      '#sticky' => TRUE,
      '#rows' => $chunks[$current_page],
      '#empty' => $this->t('No components found. You may need to run the report.')
    ];
    $table['pager'] = array(
      '#type' => 'pager'
    );
    return $table;
  }

  /**
   * Return a rendered table ready for output.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function showReport() {
    $moduleConfig = \Drupal::config('paragraphs_report.settings');
    // Build report from stored JSON in module config.
    $btn['run_button'] = [
      '#type' => 'markup',
      '#markup' => t('<div style="float:right"><a class="button" href="/admin/reports/paragraphs-report/update" onclick="return confirm(\'Update the report data with current node info?\')">Update Report Data</a></div>')
    ];
    $json = Json::decode($moduleConfig->get('report'));
    $filters = [];
    $filters['filter'] = [
      '#type' => 'markup',
      '#markup' => $this->filterForm(),
      '#allowed_tags' => array_merge(Xss::getHtmlTagList(), ['form', 'option', 'select', 'input', 'br'])
    ];
    $table = $this->formatTable($json);
    return [
      $btn,
      $filters,
      $table
    ];
  }

}
