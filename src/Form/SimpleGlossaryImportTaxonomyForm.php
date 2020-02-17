<?php

namespace Drupal\simple_glossary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Class SimpleGlossaryImportTaxonomyForm.
 *
 * @package Drupal\simple_glossary\Form
 */
class SimpleGlossaryImportTaxonomyForm extends FormBase {

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
    $form['import_terms_csv_test'] = ['#markup' => '<h2>Import By Taxonomy Term</h2>'];
    $terms = Vocabulary::loadMultiple();
    $termsList = [];
    foreach ($terms as $vid => $term) {
      $termsList[$vid] = $term->get('name');
    }
    $form['import_taxo_term'] = array (
      '#type' => 'select',
      '#title' => ('Select Taxonomy'),
      '#options' => $termsList,
      '#upload_location' => 'public://glossary_files/',
      '#empty_option' => $this->t("- Select One -")
    );
    $form['import_terms_csv_help'] = ['#markup' => '<p>In this you can select your taxonomy and their terms will get automatically loaded and you can easily import taxonomy terms. <br/>Please select a taxonomy before importing. And Taxonomy having no terms will not be imported. </p>'];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Import Taxonomy',
    ];
    return $form;
  }

  /**
   * Validating Form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('import_taxo_term') == NULL) {
      $form_state->setErrorByName('import_taxo_term', $this->t('File.'));
    }
  }

  /**
   * Submiting Form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $vid = $form_state->getValue('import_taxo_term');
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vid);
    if(count($terms) > 0){
      foreach ($terms as $term) {
        $term_data[] = array(
          'id' => $term->tid,
          'name' => $term->name,
          'descrip' => $term->description__value
        );
      }
      $res = [];
      $logsAry = [];
      foreach ($term_data as $val) {
        $temp =[];
        $temp['data'] = $val;
        $temp['result'] = SimpleGlossaryImportTaxonomyForm::saveGlossaryTaxonomyTerm(['term' => $val['name'], 'definition' => strip_tags($val['descrip'])]);
        $res[] = $temp; 
        $logsAry[] = $val['name'];
      }
      $logString = implode(',',$logsAry);
      \Drupal::logger('simple_glossary')->notice('Congratulations! Terms successfully imported. Here is list of terms: '.$logString);
      $this->messenger->addMessage('Congratulations! Terms successfully imported.');
    }
    else {
      \Drupal::logger('simple_glossary')->error('No terms available under this vocabulary.');
      $this->messenger->addMessage(('No terms available under this vocabulary.'),'error');
    }
  }

  public function saveGlossaryTaxonomyTerm($postData) {
    $termExistOrNot = SimpleGlossaryImportTaxonomyForm::helperCheckTermNameExist($postData['term']);
    $response = 0;
    $term_description = (strlen($postData['definition']) > 2048) ? substr($postData['definition'], 0, 2048) : $postData['definition'];
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
  public function helperCheckTermNameExist($term_name) {
    $data = db_select('simple_glossary_content', 't')->fields('t')->condition('term', $term_name)->execute()->fetchAssoc();
    return $data;
  }
}
