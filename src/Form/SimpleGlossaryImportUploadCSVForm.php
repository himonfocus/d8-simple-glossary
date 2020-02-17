<?php

namespace Drupal\simple_glossary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Class SimpleGlossaryImportUploadCSVForm.
 *
 * @package Drupal\simple_glossary\Form
 */
class SimpleGlossaryImportUploadCSVForm extends FormBase {

  /**
   * A form state interface instance.
   *
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * A Request stack instance.
   *
   * @var Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * A entity type manager interface instance.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SimpleGlossaryFrontendController object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   A form state variable.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   A Request stack variable.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   A entity type manager interface variable.
   */

  /**
   * Drupal\Core\Messenger\MessengerInterface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   *   Messenger Interface.
   */
  protected $messenger;

  public function __construct(StateInterface $state, RequestStack $request, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->state = $state;
    $this->request = $request;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('messenger')
    );
  }

  /**
   * Set Form Id.
   */
  public function getFormId() {
    return 'glossary_upload_csv_page';
  }

  /**
   * Building Form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // global $base_url;
    $form['import_terms_csv_sstest'] = ['#markup' => '<h2>Import By CSV</h2>'];
    $validators = ['file_validate_extensions' => ['csv']];
    $form['import_terms_csv'] = [
      '#type' => 'managed_file',
      '#name' => 'my_file',
      '#title' => $this->t('File *'),
      '#size' => 20,
      '#description' => $this->t('CSV format only'),
      '#upload_validators' => $validators,
      '#upload_location' => 'public://glossary_files/',
    ];
    $form['import_terms_csv_help'] = [
      '#markup' => $this->t('Example file of CSV format : <a href="/@base_path/assets/Glossary_Example.csv">Glossary Example.csv</a> <br /><p>Follow these instructions in CSV file:</p><ul><li>Add escapes( \ ) in term & definition both with comma i.e. fruit\,vegetables.</li><li>Enclosed value of definition with double qoute.</li><li>Replace multiline value into single line.</li></ul>' , [
        '@base_path' => drupal_get_path('module', 'simple_glossary'),
      ])
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Import',
    ];
    return $form;
  }

  /**
   * Validating Form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('import_terms_csv') == NULL) {
      $form_state->setErrorByName('import_terms_csv', $this->t('File.'));
    }
  }

  /**
   * Submiting Form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fileData = $form_state->getValue('import_terms_csv');
    $fid = $fileData['0'];
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    $path = $file->getFileUri();
    $fileAbsoluteURL = file_create_url($path);
    $csvData = file_get_contents($fileAbsoluteURL);
    $lines = explode(PHP_EOL, $csvData);
    $finalCsvDataAry = [];
    foreach ($lines as $line) {
      $finalCsvDataAry[] = str_getcsv($line, ',', '"', '\\');
    }
    unset($finalCsvDataAry[0]);
    $logCSVAry = [];
    foreach ($finalCsvDataAry as $val) {
      if (!empty($val[0])) {
        SimpleGlossaryImportUploadCSVForm::saveGlossaryTerm(['term' => trim($val[0]), 'definition' => $val[1]]);
        $logCSVAry[] = $val[0];
      }
    }
    // echo "<pre>"; print_r($logCSVAry); die;
    $logCSVFile = implode(',',$logCSVAry);
    \Drupal::logger('simple_glossary')->notice('Congratulations! Terms successfully imported. Name Of the CSV: '.$logCSVFile);
    // drupal_set_message($this->t('Congratulations! Terms successfully imported.'));
     $this->messenger->addMessage('Congratulations! Terms successfully imported.');
  }

  /**
   * HELPER METHOD.
   */
  public function saveGlossaryTerm($postData) {
    $termExistOrNot = SimpleGlossaryImportUploadCSVForm::helperCheckTermNameExist($postData['term']);
    $response = 0;
    $term_description = (strlen($postData['definition']) > 2048) ? substr($postData['definition'], 0, 2048) : $postData['definition'];
    // $keyword = ($postData['keyword'] != '')?$postData['keyword']:'';
    // $keyword_link = ($postData['keyword_link'] != '')?$postData['keyword_link']:'';
    if (empty($termExistOrNot)) {
      try {
        $id = db_insert('simple_glossary_content')->fields(['term' => $postData['term'], 'description' => htmlentities($term_description)])->execute();
        $response = ($id) ? 1 : 0;
      }
      catch (Exception $e) {
        $response = $e->getMessage();
      }
    }
    else {
      $glossary_term = $termExistOrNot['term'];
      try {
        $id = db_update('simple_glossary_content')->fields(['description' => htmlentities($term_description)])->condition('term', $glossary_term)->execute();
        $response = ($id) ? 1 : 0;
      }
      catch (Exception $e) {
        $response = $e->getMessage();
      }
    }
    return $response;
  }

  /**
   * HELPER METHOD.
   */
  public function helperCheckTermNameExist($term_name) {
    $data = db_select('simple_glossary_content', 't')->fields('t')->condition('term', $term_name)->execute()->fetchAssoc();
    return $data;
  }

}
