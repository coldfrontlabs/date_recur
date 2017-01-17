<?php

namespace Drupal\date_recur\Plugin\DateRecurOccurrenceHandler;

use Drupal\date_recur\DateRecurRRule;
use Drupal\date_recur\Plugin\DateRecurOccurrenceHandlerBase;
use Drupal\date_recur\Plugin\DateRecurOccurrenceHandlerInterface;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\date_recur\Plugin\Field\FieldType\DateRecurItem;
use Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem;
use Drupal\field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\field\FieldStorageConfigInterface;

/**
 * @DateRecurOccurrenceHandler(
 *  id = "date_recur_occurrence_handler",
 *  label = @Translation("Default occurrence handler"),
 * )
 */
class DefaultDateRecurOccurrenceHandler extends DateRecurOccurrenceHandlerBase implements DateRecurOccurrenceHandlerInterface, ContainerFactoryPluginInterface {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * @var DateRecurItem
   */
  protected $item;

  /**
   * @var DateRecurRRule
   */
  protected $rruleObject;

  /**
   * @var bool
   */
  protected $isRecurring;

  /**
   * @var string
   */
  protected $tableName;

  /**
   * Construct.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param Connection $database
   *   The database service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Connection $database
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    // Assume no recurrence until declared otherwise in init().
    $this->isRecurring = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database')
    );
  }

  public function init(DateRecurItem $item) {
    $this->item = $item;
    if (!empty($item->rrule)) {
      $this->rruleObject = new DateRecurRRule($item->rrule, $item->start_date, $item->end_date, $item->timezone);
      $this->isRecurring = TRUE;
    }
    else {
      $this->isRecurring = FALSE;
    }
    $this->tableName = $this->getOccurrenceTableName($this->item->getFieldDefinition());
  }

  public function getOccurrencesForDisplay($start = NULL, $end = NULL, $num = NULL) {
    if (!$this->isRecurring) {
      return [];
    }
    return $this->rruleObject->getOccurrences($start, $end, $num);
  }

  public function humanReadable() {
    if (!$this->isRecurring) {
      return '';
    }
    return $this->rruleObject->humanReadable();
  }

  public function isInfinite() {
    if (!$this->isRecurring) {
      return 0;
    }
    return (int) $this->rruleObject->isInfinite();
  }

  public function onSave($update, $field_delta) {
    $entity_id = $this->item->getEntity()->id();
    $revision_id = $this->item->getEntity()->getRevisionId();
    $field_name = $this->item->getFieldDefinition()->getName();

    if ($update) {
      $this->database->delete($this->tableName)
        ->condition('entity_id', $entity_id)
        ->condition('field_delta', $field_delta)
        ->execute();
    }

    $fields = ['entity_id', 'revision_id', 'field_delta', $field_name . '_value', $field_name . '_end_value', 'delta'];
    $dates = $this->getOccurrencesForCacheStorage();
    $delta = 0;
    $rows = [];
    foreach ($dates as $date) {
      $rows[] = [
        'entity_id' => $entity_id,
        'revision_id' => $revision_id,
        'field_delta' => $field_delta,
        $field_name . '_value' => $date['value'],
        $field_name . '_end_value' => $date['end_value'],
        'delta' => $delta,
      ];
      $delta++;
    }
    $q = $this->database->insert($this->tableName)->fields($fields);
    foreach ($rows as $row) {
      $q->values($row);
    }
    $q->execute();
  }

  protected function getOccurrencesForCacheStorage() {
    // Get storage format from settings.
    switch ($this->item->getFieldDefinition()->getSetting('daterange_type')) {
      case DateRangeItem::DATETIME_TYPE_DATE:
        $storageFormat = DATETIME_DATE_STORAGE_FORMAT;
        break;
      default:
        $storageFormat = DATETIME_DATETIME_STORAGE_FORMAT;
        break;
    }

    if (!$this->isRecurring) {
      if (empty($this->item->end_date)) {
        $this->item->end_date = $this->item->start_date;
      }
      return [[
        'value' => DateRecurRRule::massageDateValueForStorage($this->item->start_date, $storageFormat),
        'end_value' => DateRecurRRule::massageDateValueForStorage($this->item->end_date, $storageFormat),
      ]];
    }
    else {
      $until = new \DateTime();
      $until->add(new \DateInterval($this->item->getFieldDefinition()->getSetting('precreate')));
      return $this->rruleObject->getOccurrencesForCacheStorage($until, $storageFormat);
    }

  }

  public function onSaveMaxDelta($field_delta) {
    $this->database->delete($this->tableName)
      ->condition('entity_id', $this->item->getEntity()->id())
      ->condition('field_delta', $field_delta, '>')
      ->execute();
  }

  public function onDelete() {
    $table_name = $this->getOccurrenceTableName($this->item->getFieldDefinition());
    $this->database->delete($table_name)->condition('entity_id', $this->item->getEntity()->id());
  }

  public function onDeleteRevision() {
    $table_name = $this->getOccurrenceTableName($this->item->getFieldDefinition());
    $this->database->delete($table_name)->condition('revision_id', $this->item->getEntity()->getRevisionId());
  }



  public function onFieldCreate(FieldConfigInterface $field) {
    $this->createOccurrenceTable($field);
  }

  public function onFieldUpdate(FieldConfigInterface $field) {
    // Nothing to do.
  }

  public function onFieldDelete(FieldConfigInterface $field) {
    $this->dropOccurrenceTable($field);
  }


  protected function createOccurrenceTable(FieldDefinitionInterface $field) {
    $entity_type = $field->getTargetEntityTypeId();
    $field_name = $field->getName();
    $table_name = $this->getOccurrenceTableName($field);

    $spec = $this->getOccurrenceTableSchema($field);
    $spec['description'] = 'Date recur cache for ' . $entity_type . '.' . $field_name;
    $schema = $this->database->schema();
    $schema->createTable($table_name, $spec);
  }

  protected function dropOccurrenceTable(FieldConfigInterface $field) {
    $table_name = $this->getOccurrenceTableName($field);
    $schema = $this->database->schema();
    $schema->dropTable($table_name);
  }

  public function getOccurrenceTableSchema(FieldDefinitionInterface $field) {
    $field_name = $field->getName();
    $schema = [
      'fields' => [
        'entity_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => "Entity id",
        ],
        'revision_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => "Entity revision id",
        ],
        'field_delta' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'delta' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        $field_name . '_value' => [
          'description' => 'Start date',
          'type' => 'varchar',
          'length' => 20,
        ],
        $field_name . '_end_value' => [
          'description' => 'End date',
          'type' => 'varchar',
          'length' => 20,
        ],
      ],
      'indexes' => [
        'value' => ['entity_id', $field_name . '_value'],
      ],
    ];
    return $schema;
  }

  public function viewsData(FieldStorageConfigInterface $field_storage, $data) {
    dsm('init');
    ksm($data);
    if (empty($data)) {
      return [];
    }
    $field_name = $field_storage->getName();
    list($table_alias, $revision_table_alias) = array_keys($data);

    // @todo: Revision support.
    unset($data[$revision_table_alias]);
    $recur_table_name = $this->getOccurrenceTableName($field_storage);

    $columns_to_move = [
      $field_name,
      $field_name . '_value',
      $field_name . '_end_value',
    ];

    $field_table = $data[$table_alias];
    $recur_table = $field_table;

    // Remove date columns from field data table.
    foreach ($columns_to_move as $column) {
      unset($field_table[$column]);
    }

    // todo: Add in RRULE support.


    // Remove fields not present in date_recur tables and change the join to
    // the date_recur cache table.
    $join_key = reset(array_keys($field_table['table']['join']));
    $recur_table['table']['join'] = $field_table['table']['join'];
    $recur_table['table']['join'][$join_key]['table'] = $recur_table_name;
    $recur_table['table']['join'][$join_key]['extra'] = array();

    // Update table name references.
    $handler_keys = ['argument', 'filter', 'sort', 'field'];
    foreach ($recur_table as $column_name => &$column_data) {
      if (!in_array($column_name, array_merge($columns_to_move, ['table']))) {
        unset($recur_table[$column_name]);
      }
      else if ($column_name != 'table') {
        foreach ($handler_keys as $key) {
          if (!empty($column_data[$key]['table'])) {
            $column_data[$key]['table'] = $recur_table_name;
            $column_data[$key]['additional fields'] = ['field_date_value', 'field_date_end_value', 'delta', 'field_delta'];
          }
        }
      }
    }

    $return_data = [$recur_table_name => $recur_table, $table_alias => $field_table];
    dsm('ret');
    ksm($return_data);
    return $return_data;
  }
}