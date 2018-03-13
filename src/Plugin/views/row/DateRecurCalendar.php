<?php
namespace Drupal\date_recur\Plugin\views\row;
use DateTimeZone;
use Drupal\calendar\CalendarEvent;
use Drupal\calendar\CalendarHelper;
use Drupal\calendar\CalendarViewsTrait;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\date_recur\Plugin\Field\FieldType\DateRecurItem;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Plugin\views\argument\Date;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
/**
 * Plugin which creates a view on the resulting object and formats it as a
 * Calendar entity.
 *
 * @ViewsRow(
 *   id = "date_recur_calendar_row",
 *   title = @Translation("Calendar entities (with Date Recur)"),
 *   help = @Translation("Display the content as calendar entities."),
 *   theme = "views_view_row_calendar",
 *   register_theme = FALSE,
 * )
 */
class DateRecurCalendar extends RowPluginBase {
  use CalendarViewsTrait;
  /**
   * @var \Drupal\Core\Datetime\DateFormatter $dateFormatter
   *   The date formatter service.
   */
  protected $dateFormatter;
  /**
   * @var $entityType
   *   The entity type being handled in the preRender() function.
   */
  protected $entityType;
  /**
   * @var $entities
   *   The entities loaded in the preRender() function.
   */
  protected $entities = [];
  /**
   * @var $dateFields
   *   todo document.
   */
  protected $dateFields = [];
  /**
   * @var \Drupal\views\Plugin\views\argument\Formula
   */
  protected $dateArgument;
  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;
  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
  }
  /**
   * Constructs a Calendar row plugin object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DateFormatter $date_formatter, EntityFieldManagerInterface $field_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dateFormatter = $date_formatter;
    $this->fieldManager = $field_manager;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('entity_field.manager')
    );
  }
  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['date_fields'] = ['default' => []];
    $options['colors'] = [
      'contains' => [
        'legend'                     => ['default' => ''],
        'calendar_colors_type'       => ['default' => []],
        'taxonomy_field'             => ['default' => ''],
        'calendar_colors_vocabulary' => ['default' => []],
        'calendar_colors_taxonomy'   => ['default' => []],
        'calendar_colors_group'      => ['default' => []],
      ]
    ];
    return $options;
  }
  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['markup'] = [
      '#markup' => $this->t("The calendar row plugin will format view results as calendar items. Make sure this display has a 'Calendar' format and uses a 'Date' contextual filter, or this plugin will not work correctly."),
    ];
    $form['colors'] = [
      '#type'        => 'fieldset',
      '#title'       => $this->t('Legend Colors'),
      '#description' => $this->t('Set a hex color value (like #ffffff) to use in the calendar legend for each content type. Items with empty values will have no stripe in the calendar and will not be added to the legend.'),
    ];
    $options = [];
    // @todo Allow strip options for any bundles of any entity type.
    if ($this->view->getBaseTables()['node_field_data']) {
      $options['type'] = $this->t('Based on Content Type');
    }
    if (\Drupal::moduleHandler()->moduleExists('taxonomy')) {
      $options['taxonomy'] = $this->t('Based on Taxonomy');
    }
    // If no option is available, stop here.
    if (empty($options)) {
      return;
    }
    $form['colors']['legend'] = [
      '#title'         => $this->t('Stripes'),
      '#description'   => $this->t('Add stripes to calendar items.'),
      '#type'          => 'select',
      '#options'       => $options,
      '#empty_value'   => (string) $this->t('None'),
      '#default_value' => $this->options['colors']['legend'],
    ];
    if ($this->view->getBaseTables()['node_field_data']) {
      $colors = $this->options['colors']['calendar_colors_type'];
      $type_names = node_type_get_names();
      foreach ($type_names as $key => $name) {
        $form['colors']['calendar_colors_type'][$key] = [
            '#title'            => $name,
            '#default_value'    => isset($colors[$key]) ? $colors[$key] : CALENDAR_EMPTY_STRIPE,
            '#dependency'       => ['edit-row-options-colors-legend' => ['type']],
            '#type'             => 'textfield',
            '#size'             => 7,
            '#maxlength'        => 7,
            '#element_validate' => [[$this, 'validateHexColor']],
            '#prefix'           => '<div class="calendar-colorpicker-wrapper">',
            '#suffix'           => '<div class="calendar-colorpicker"></div></div>',
            '#attributes'       => ['class' => ['edit-calendar-colorpicker']],
            '#attached'         => [
              // Add Farbtastic color picker and the js to trigger it.
              'library' => [
                'calendar/calendar.colorpicker',
              ],
            ],
          ] + $this->visibleOnLegendState('type');
      }
    }
    if (\Drupal::moduleHandler()->moduleExists('taxonomy')) {
      // Get the display's field names of taxonomy fields.
      $vocabulary_field_options = [];
      $fields = $this->displayHandler->getOption('fields');
      foreach ($fields as $name => $field_info) {
        // Select the proper field type.
        if ($this->isTermReferenceField($field_info, $this->fieldManager)) {
          $vocabulary_field_options[$name] = $field_info['label'] ?: $name;
        }
      }
      if (empty($vocabulary_field_options)) {
        return;
      }
      $form['colors']['taxonomy_field'] = [
          '#title'         => t('Term field'),
          '#type'          => !empty($vocabulary_field_options) ? 'select' : 'hidden',
          '#default_value' => $this->options['colors']['taxonomy_field'],
          '#empty_value'   => (string) $this->t('None'),
          '#description'   => $this->t("Select the taxonomy term field to use when setting stripe colors. This works best for vocabularies with only a limited number of possible terms."),
          '#options'       => $vocabulary_field_options,
          // @todo Is this in the form api?
          '#dependency'    => ['edit-row-options-colors-legend' => ['taxonomy']],
        ] + $this->visibleOnLegendState('taxonomy');
      if (empty($vocabulary_field_options)) {
        $form['colors']['taxonomy_field']['#options'] = ['' => ''];
        $form['colors']['taxonomy_field']['#suffix'] = $this->t('You must add a term field to this view to use taxonomy stripe values. This works best for vocabularies with only a limited number of possible terms.');
      }
      // Get the Vocabulary names.
      $vocab_vids = [];
      foreach ($vocabulary_field_options as $field_name => $label) {
        // @todo Provide storage manager via Dependency Injection
        $field_config = \Drupal::entityTypeManager()
          ->getStorage('field_config')
          ->loadByProperties(['field_name' => $field_name]);
        // @TODO refactor
        reset($field_config);
        $key = key($field_config);
        $data = \Drupal::config('field.field.' . $field_config[$key]->getOriginalId())
          ->getRawData();
        if ($target_bundles = $data['settings']['handler_settings']['target_bundles']) {
          // Fields must target bundles set.
          reset($target_bundles);
          $vocab_vids[$field_name] = key($target_bundles);
        }
      }
      if (empty($vocab_vids)) {
        return;
      }
      $this->options['colors']['calendar_colors_vocabulary'] = $vocab_vids;
      $form['colors']['calendar_colors_vocabulary'] = [
          '#title' => t('Vocabulary Legend Types'),
          '#type'  => 'value',
          '#value' => $vocab_vids,
        ] + $this->visibleOnLegendState('taxonomy');
      // Get the Vocabulary term id's and map to colors.
      // @todo Add labels for each Vocabulary.
      $term_colors = $this->options['colors']['calendar_colors_taxonomy'];
      foreach ($vocab_vids as $field_name => $vid) {
        $vocab = \Drupal::entityTypeManager()
          ->getStorage("taxonomy_term")
          ->loadTree($vid);
        foreach ($vocab as $key => $term) {
          $form['colors']['calendar_colors_taxonomy'][$term->tid] = [
              '#title'            => $this->t($term->name),
              '#default_value'    => isset($term_colors[$term->tid]) ? $term_colors[$term->tid] : CALENDAR_EMPTY_STRIPE,
              '#access'           => !empty($vocabulary_field_options),
              '#dependency_count' => 2,
              '#dependency'       => [
                'edit-row-options-colors-legend'         => ['taxonomy'],
                'edit-row-options-colors-taxonomy-field' => [$field_name],
              ],
              '#type'             => 'textfield',
              '#size'             => 7,
              '#maxlength'        => 7,
              '#element_validate' => [[$this, 'validateHexColor']],
              '#prefix'           => '<div class="calendar-colorpicker-wrapper">',
              '#suffix'           => '<div class="calendar-colorpicker"></div></div>',
              '#attributes'       => ['class' => ['edit-calendar-colorpicker']],
              '#attached'         => [
                // Add Farbtastic color picker and the js to trigger it.
                'library' => [
                  'calendar/calendar.colorpicker',
                ],
              ],
            ] + $this->visibleOnLegendState('taxonomy');
        }
      }
    }
  }
  /**
   *  Check to make sure the user has entered a valid 6 digit hex color.
   */
  public function validateHexColor($element, FormStateInterface $form_state) {
    if (!$element['#required'] && empty($element['#value'])) {
      return;
    }
    if (!preg_match('/^#(?:(?:[a-f\d]{3}){1,2})$/i', $element['#value'])) {
      $form_state->setError($element, $this->t("'@color' is not a valid hex color", ['@color' => $element['#value']]));
    }
    else {
      $form_state->setValueForElement($element, $element['#value']);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function preRender($result) {
    // Preload each entity used in this view from the cache. This provides all
    // the entity values relatively cheaply, and we don't need to do it
    // repeatedly for the same entity if there are multiple results for one
    // entity.
    $ids = [];
    /** @var $row \Drupal\views\ResultRow */
    foreach ($result as $row) {
      // Use the entity id as the key so we don't create more than one value per
      // entity.
      $entity = $row->_entity;
      // Node revisions need special loading.
      if (isset($this->view->getBaseTables()['node_revision'])) {
        $this->entities[$entity->id()] = \Drupal::entityTypeManager()
          ->getStorage('node')
          ->loadRevision($entity->id());
      }
      else {
        $ids[$entity->id()] = $entity->id();
      }
    }
    $base_tables = $this->view->getBaseTables();
    $base_table = key($base_tables);
    $table_data = Views::viewsData()->get($base_table);
    $this->entityType = $table_data['table']['entity type'];
    if (!empty($ids)) {
      $this->entities = \Drupal::entityTypeManager()
        ->getStorage($this->entityType)
        ->loadMultiple($ids);
    }
    // Identify the date argument and fields that apply to this view. Preload
    // the Date Views field info for each field, keyed by the field name, so we
    // know how to retrieve field values from the cached node.
    $data = CalendarHelper::dateViewFields($this->entityType);
    $date_fields = [];
    /** @var $handler \Drupal\views\Plugin\views\argument\Formula */
    foreach ($this->view->getDisplay()->getHandlers('argument') as $handler) {
      if ($handler instanceof Date) {
        // Strip "_calendar" from the field name.
        $fieldName = $handler->realField;
        if (!empty($data['alias'][$handler->table . '_' . $fieldName])) {
          $date_fields[$fieldName] = $data['alias'][$handler->table . '_' . $fieldName];
          $this->dateFields = $date_fields;
        }
        $this->dateArgument = $handler;
      }
    }
  }
  /**
   * {@inheritdoc}
   */
  public function render($row) {
    $rows = [];
    $id = $row->_entity->id();
    if (!is_numeric($id)) {
      return $rows;
    }
    /** @var \Drupal\calendar\CalendarDateInfo $dateInfo */
    $dateInfo = $this->dateArgument->view->dateInfo;
    // Minimum date appears in the calendar.
    $dateMin = $dateInfo->getMinDate();
    // Maximum date appears in the calendar.
    $dateMax = $dateInfo->getMaxDate();
    // Timezone.
    $tz = new \DateTimeZone(timezone_name_get($dateInfo->getTimezone()));
    // There could be more than one date field in a view so iterate through all
    // of them to find the right values for this view result.
    foreach ($this->dateFields as $field_name => $info) {
      // Clone this entity so we can change it's values without altering other
      // occurrences of this entity on the same page, for example in an
      // "Upcoming" block.
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = clone($this->entities[$id]);
      if (empty($entity)) {
        continue;
      }
      // Get the field name used in query.
      $query_field = substr($info['query_name'], 0, 60);
      // Get the real name of the field.
      $real_field_name = str_replace('_value', '', $field_name);
      // Get the field definition.
      $field_definition = $entity->getFieldDefinition($real_field_name);
      // Get date type.
      $datetime_type = $field_definition->getSetting('datetime_type');
      // Get storage format.
      $storage_format = $datetime_type == 'date' ? DATETIME_DATE_STORAGE_FORMAT : DATETIME_DATETIME_STORAGE_FORMAT;
      // Reset helper variables.
      $granularity = 'second';
      $item_start_date = NULL;
      $item_end_date = NULL;
      $delta = 0;
      if ($info['is_field'] && isset($row->_entity->{$real_field_name})) {
        /** @var \Drupal\Core\Field\FieldItemListInterface $field */
        $field = $row->_entity->{$real_field_name};
        $granularity = 'week';
        // "DateRecur" support.
        if (isset($field[$delta]) && $field[$delta] instanceof DateRecurItem) {
          /** @var \Drupal\date_recur\Plugin\Field\FieldType\DateRecurItem $fieldItem */
          $fieldItem = $field[$delta];
          // Get DateRecur Occurrence Handler.
          $occurrenceHandler = $fieldItem->getOccurrenceHandler();
          // If this field is a DateRecur field.
          if ($occurrenceHandler->isRecurring()) {
            // Try to create DateTime object from the date comes from the query.
            if (property_exists($row, $query_field)) {
              $dateMin = \DateTime::createFromFormat($storage_format, $row->{$query_field});
              $dateMax = NULL;
            }

            // FIXME - Force timestamp conversion to UTC.
            $ts = $dateMin->getTimestamp() + $dateMin->getOffset();
            $dateMin->setTimestamp($ts);

            // Get a list of occurrences for display.
            $occurrences = $occurrenceHandler->getOccurrencesForDisplay($dateMin, $dateMax, 1);
            if (!empty($occurrences)) {
              foreach ($occurrences as $_delta => $occurrence) {
                /** @var \DateTime $start */
                $start = $occurrence['value'];
                /** @var \DateTime $end */
                $end = $occurrence['end_value'];
                $item_start_date = new \DateTime();
                $item_start_date->setTimestamp($start->getTimestamp());
                $item_start_date->setTimezone($tz);
                // FIXME - Better method to convert timestamp (back) to UTC.
                // DateRecur values already have offset, so we need to convert
                // value back to UTC.
                $ts = $item_start_date->getTimestamp() - $item_start_date->getOffset();
                $item_start_date->setTimestamp($ts);
                $item_end_date = new \DateTime();
                $item_end_date->setTimestamp($end->getTimestamp());
                $item_end_date->setTimezone($tz);
                // FIXME - Better method to convert timestamp (back) to UTC.
                // DateRecur values already have offset, so we need to convert
                // value back to UTC.
                $ts = $item_end_date->getTimestamp() - $item_end_date->getOffset();
                $item_end_date->setTimestamp($ts);
                // Finally, create event objects, and merge them into the row.
                $items = $this->getEventItems($row->_entity, $item_start_date, $item_end_date, $tz, $granularity);
                if (!empty($items)) {
                  $rows = array_merge($rows, $items);
                }
              }
              // At this point, all DateRecur occurrences are merged into $rows
              // so we can continue adding date items with the next field.
              continue;
            }
          }
        }
        // "Date" and "DateRange" support.
        $date = NULL;
        $date_end = NULL;
        if (isset($field->getValue()[$delta]['value'])) {
          $date = $field->getValue()[$delta]['value'];
        }
        if (isset($field->getValue()[$delta]['end_value'])) {
          $date_end = $field->getValue()[$delta]['end_value'];
        }
        if (empty($date_end)) {
          $date_end = $date;
        }
        $item_start_date = \DateTime::createFromFormat($storage_format, $date, $tz);
        $item_end_date = \DateTime::createFromFormat($storage_format, $date_end, $tz);
      }
      elseif ($field = $entity->get($field_name)) {
        $item = $field->getValue();
        if (isset($item[$delta]['value'])) {
          $item_start_date = new \DateTime();
          $item_start_date->setTimestamp($item[$delta]['value']);
          $item_end_date = $item_start_date;
        }
        if (isset($item[$delta]['end_value'])) {
          $item_end_date->setTimestamp($item[$delta]['end_value']);
        }
      }
      // If we don't have a date value, go no further.
      if (empty($item_start_date)) {
        continue;
      }
      $items = $this->getEventItems($entity, $item_start_date, $item_end_date, $tz, $granularity);
      if (!empty($items)) {
        $rows = array_merge($rows, $items);
      }
    }
    return $rows;
  }
  public function getEventItems($entity, $item_start_date, $item_end_date, $tz, $granularity) {
    $event = new CalendarEvent($entity);
    $event->setStartDate($item_start_date);
    $event->setEndDate($item_end_date);
    $event->setTimezone($tz);
    $event->setGranularity($granularity);
    $rows = [];
    /** @var \Drupal\calendar\CalendarEvent[] $events */
    $events = $this->explode_values($event);
    foreach ($events as $event) {
      switch ($this->options['colors']['legend']) {
        case 'type':
          if ($event->getEntityTypeId() == 'node') {
            $this->nodeTypeStripe($event);
          }
          break;
        case 'taxonomy':
          $this->calendarTaxonomyStripe($event);
          break;
      }
      $rows[] = $event;
    }
    return $rows;
  }
  /**
   * @todo rename and document
   *
   * @param \Drupal\calendar\CalendarEvent $event
   * @return array
   */
  function explode_values($event) {
    $rows = [];
    /** @var \Drupal\calendar\CalendarDateInfo $dateInfo */
    $dateInfo = $this->dateArgument->view->dateInfo;
    $tz = new \DateTimeZone(timezone_name_get($dateInfo->getTimezone()));
    $now = $event->getStartDate()->format('Y-m-d');
    $to = $event->getEndDate()->format('Y-m-d');
    $next = new \DateTime();
    $next->setTimestamp($event->getStartDate()->getTimestamp());
    if ($tz->getName() != $event->getTimezone()->getName()) {
      // Make $start and $end (derived from $node) use the timezone $to_zone,
      // just as the original dates do.
      $next->setTimezone($event->getTimezone());
    }
    // If the event starts earlier than minimum date appears in the calendar.
    if ($now < $dateInfo->getMinDate()->format('Y-m-d')) {
      $now = $dateInfo->getMinDate()->format('Y-m-d');
    }
    // If 'end' date is not set.
    if (empty($to) || $now > $to) {
      $to = $now;
    }
    // First day of the week.
    $fd = (int) \Drupal::config('system.date')->get('first_day');
    // Store H:i:s for later use.
    $startH = $event->getStartDate()->format('H');
    $starti = $event->getStartDate()->format('i');
    $starts = $event->getStartDate()->format('s');
    // Store H:i:s for later use.
    $endH = $event->getEndDate()->format('H');
    $endi = $event->getEndDate()->format('i');
    $ends = $event->getEndDate()->format('s');
    // $now and $next are midnight (in display timezone) on the first day where
    // node will occur.
    // $to is midnight on the last day where node will occur.
    // All three were limited by the min-max date range of the view.
    $position = 0;
    while (!empty($now) && $now <= $to) {
      /** @var \Drupal\calendar\CalendarEvent $entity */
      $entity = clone $event;
      // Minimum date appears in the calendar.
      $start = $this->dateFormatter->format($dateInfo->getMinDate()
        ->getTimestamp(), 'custom', 'Y-m-d H:i:s');
      $end_ts = strtotime('+1 day -1 second', $dateInfo->getMaxDate()
        ->getTimestamp());
      // Maximum date appears in the calendar.
      $end = $this->dateFormatter->format($end_ts, 'custom', 'Y-m-d H:i:s');
      // TODO really need this?
      $next->setTimestamp(strtotime('+1 day -1 second', $next->getTimestamp()));
      // Get start and end of item, formatted the same way.
      $item_start = $this->dateFormatter->format($event->getStartDate()
        ->getTimestamp(), 'custom', 'Y-m-d H:i:s');
      $item_end = $this->dateFormatter->format($event->getEndDate()
        ->getTimestamp(), 'custom', 'Y-m-d H:i:s');
      // Calculate start date for this event.
      $start_string = $item_start < $start ? $start : $item_start;
      // Set a new start date for this event.
      $entity->setStartDate(new \DateTime($start_string));
      // Restore H:i:s.
      $entity->getStartDate()->setTime($startH, $starti, $starts);
      // Calculate end date for this event.
      $end_string = !empty($item_end) ? ($item_end > $end ? $end : $item_end) : NULL;
      // Set a new end date for this event.
      $entity->setEndDate(new \DateTime($end_string));
      // Restore H:i:s.
      $entity->getEndDate()->setTime($endH, $endi, $ends);
      // TODO don't hardcode granularity and increment.
      $granularity = 'hour';
      $increment = 1;
      // If calendar view supports week-range, try to explode multi-week events
      // into multiple one-week events.
      // Get the day of the week by the event.
      $day_week_day = $entity->getStartDate()->format('w');
      if ($dateInfo->getCalendarType() == 'day') {
        // Get the current day.
        $range_first = new \DateTime();
        $range_first->setTimestamp($dateInfo->getMinDate()->getTimestamp());
        // Get the end of the current day.
        $range_last = new \DateTime();
        $range_last->setTimestamp($range_first->getTimestamp());
        $range_last->modify('+1 day -1 second');
      }
      else {
        // Get the first day of the current week.
        $range_first = new \DateTime();
        $range_first->setTimestamp(strtotime($now));
        $range_first->modify('-' . ((7 + $day_week_day - $fd) % 7) . ' days');
        // $range_first->modify('-1 second');
        // Get the last day of the current week.
        $range_last = new \DateTime();
        $range_last->setTimestamp($range_first->getTimestamp());
        $range_last->modify('+7 days -1 second');
      }
      // Initial timestamp value for do..while loop.
      $event_end = $entity->getEndDate()->getTimestamp();
      // If the event will finish after the current week/day.
      if ($event_end > $range_last->getTimestamp()) {
        // Explode event into multiple events. So, there will be an event on
        // each week/day.
        do {
          $Y = $range_first->format('Y');
          $m = $range_first->format('m');
          $d = $range_first->format('d');
          // Make a new instance to avoid cloning object with reference.
          $new_start = new \DateTime();
          $new_start->setTimezone($entity->getStartDate()->getTimezone());
          $new_start->setDate($Y, $m, $d);
          $new_start->setTime(0, 0, 0);
          // Update start date on event.
          $entity->setStartDate($new_start);
          // Variables for "all day" calculation.
          $allDayStart = $entity->getStartDate()->format('Y-m-d H:i:s');
          $allDayEnd = $entity->getEndDate()->format('Y-m-d H:i:s');
          // Get end dates for "all day" calculation.
          $rangeEnds = $range_last->getTimestamp();
          $eventEnds = $entity->getEndDate()->getTimestamp();
          // If event will be end after the current week, we use the last
          // day of the current week as the event end date for "all day"
          // calculation.
          if ($rangeEnds < $eventEnds) {
            $allDayEnd = $range_last->format('Y-m-d H:i:s');
          }
          // Checks if an event covers all day.
          $allDay = CalendarHelper::dateIsAllDay($allDayStart, $allDayEnd, $granularity, $increment);
          // Set the all day property.
          $entity->setAllDay($allDay);
          // Append event to the rows.
          $rows[] = clone $entity;
          // Go to next period.
          if ($dateInfo->getCalendarType() == 'day') {
            $range_first->modify('+1 day');
            $range_last->modify('+1 day');
          }
          else {
            $range_first->modify('+1 week');
            $range_last->modify('+1 week');
          }
        } while ($range_first->getTimestamp() < $event_end && $range_first->getTimestamp() < $end_ts);
      }
      else {
        $entity->setAllDay(CalendarHelper::dateIsAllDay($entity->getStartDate()
          ->format('Y-m-d H:i:s'), $entity->getEndDate()
          ->format('Y-m-d H:i:s'), $granularity, $increment));
        $calendar_start = new \DateTime();
        $calendar_start->setTimestamp($entity->getStartDate()->getTimestamp());
        if (isset($entity) && (empty($calendar_start))) {
          // If no date for the node and no date in the item there is no way to
          // display it on the calendar.
          unset($entity);
        }
        else {
          $rows[] = $entity;
          unset($entity);
        }
      }
      // TODO really need this?
      $next->setTimestamp(strtotime('+1 second', $next->getTimestamp()));
      $now = $this->dateFormatter->format($next->getTimestamp(), 'Y-m-d');
      $position++;
    }
    return $rows;
  }
  /**
   * Create a stripe base on node type.
   *
   * @param \Drupal\calendar\CalendarEvent $event
   *   The event result object.
   */
  function nodeTypeStripe(&$event) {
    $colors = isset($this->options['colors']['calendar_colors_type']) ? $this->options['colors']['calendar_colors_type'] : [];
    if (empty($colors)) {
      return;
    }
    $type_names = node_type_get_names();
    $bundle = $event->getBundle();
    $label = '';
    $stripeHex = '';
    if (array_key_exists($bundle, $type_names) || $colors[$bundle] == CALENDAR_EMPTY_STRIPE) {
      $label = $type_names[$bundle];
    }
    if (array_key_exists($bundle, $colors)) {
      $stripeHex = $colors[$bundle];
    }
    $event->addStripeLabel($label);
    $event->addStripeHex($stripeHex);
  }
  /**
   * Create a stripe based on a taxonomy term.
   *
   * @param CalendarEvent $event
   */
  function calendarTaxonomyStripe(&$event) {
    $colors = isset($this->options['colors']['calendar_colors_taxonomy']) ? $this->options['colors']['calendar_colors_taxonomy'] : [];
    if (empty($colors)) {
      return;
    }
    $entity = $event->getEntity();
    $term_field_name = $this->options['colors']['taxonomy_field'];
    if ($entity->hasField($term_field_name) && $terms_for_entity = $entity->get($term_field_name)) {
      /** @var EntityReferenceFieldItemListInterface $item */
      foreach ($terms_for_entity as $item) {
        $tid = $item->getValue()['target_id'];
        $term = Term::load($tid);
        if (!array_key_exists($tid, $colors) || $colors[$tid] == CALENDAR_EMPTY_STRIPE) {
          continue;
        }
        $event->addStripeLabel($term->name->value);
        $event->addStripeHex($colors[$tid]);
      }
    }
    return;
  }
  /**
   * Get form options for hiding elements based on legend type.
   * @param $mode
   *
   * @return array
   */
  protected function visibleOnLegendState($mode) {
    return [
      '#states' => [
        'visible' => [
          ':input[name="row_options[colors][legend]"]' => array('value' => $mode),
        ],
      ],
    ];
  }
}