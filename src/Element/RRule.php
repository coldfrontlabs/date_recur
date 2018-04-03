<?php

namespace Drupal\date_recur\Element;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\date_recur\DateRecurRRule;

/**
 * Provides RRule Element.
 *
 * @FormElement("rrule")
 */
class RRule extends FormElement {

  /**
   * Render API callback: Validates the RRule element.
   *
   * @param array $element
   *   The form element whose value is being validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   */
  public static function validateRRule(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($element['#parents']);
    $value = static::getRRule($element, $values, $form_state);
    $form_state->setValueForElement($element, $value);
    $element['#value'] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#element_validate' => [
        [$class, 'validateRRule'],
      ],
      '#process' => [
        [$class, 'processRRule'],
        [$class, 'processGroup'],
      ],
      '#pre_render' => [
        [$class, 'preRenderGroup'],
      ],
      '#theme' => 'rrule',
      '#start_date' => new DrupalDateTime(),
      '#end_date' => NULL,
      // Reference to the date field.
      '#start_date_element' => NULL,
      '#timezone' => NULL,
      '#attached' => [
        'library' => ['date_recur/rrule'],
        'drupalSettings' => [
          'date_recur' => [],
        ],
      ],
      'repeat' => [],
      'freq' => [],
      'interval' => [],
      'byday' => [],
      'pos' => [],
      'bymonth' => [],
      'byhour' => [],
      'byminute' => [],
      'bysecond' => [],
      'end' => [],
      'count' => [],
      'until' => [],
      'exdate' => [],
      'summary' => [],
      'rrule' => [],
    ];
  }

  /**
   * Render API callback: Expands the RRule element into a recurring input set.
   *
   * @param array $element
   *   The form element whose value is being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The form element whose value has been processed.
   */
  public static function processRRule(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $element['#tree'] = TRUE;
    $byday_pos = [];
    $byday = [];
    $tz = new \DateTimeZone(drupal_get_user_timezone());
    $now = new \DateTime('now', $tz);
    $tz_offset = $now->format('P');
    $start_date_name = NULL;
    $rrule = NULL;
    $repeat = 0;

    if (isset($element['#start_date_element']['#value']) && !isset($element['#start_date'])) {
      $element['#start_date'] = $element['#start_date_element']['#value'];
    }
    if (isset($element['#default_value'])
      && !empty($element['#default_value'])
      && isset($element['#start_date'])
    ) {
      try {
        $repeat = 1;
        $end_date = isset($element['#end_date']) ? $element['#end_date'] : NULL;
        $timezone = isset($element['#timezone']) ? $element['#timezone'] : NULL;
        $rrule = new DateRecurRRule($element['#default_value'], $element['#start_date'], $end_date, $timezone);
      }
      catch (\Exception $e) {
        \Drupal::logger('date recur')
          ->notice('Unable to create Date Recur RRule: @exception', ['@exception' => $e->getMessage()]);
        $rrule = NULL;
      }
    }

    // If no rule is set then default to Weekly and turn repeating off.
    if (FALSE === $rrule instanceof DateRecurRRule) {
      $repeat = 0;
      $rrule = new DateRecurRRule('FREQ=WEEKLY', new DrupalDateTime(), NULL, $element['#timezone']);
    }
  
    $parts = array_merge($rrule->getSetParts(), $rrule->getParts());
   
    // Add the users timezone to be able to conpensate for this.
    $element['#attached']['drupalSettings']['date_recur'] += [
      'timezone' => $tz->getName(),
      'offset' => $tz_offset,
    ];

    if (isset($element['#start_date_element'])) {
      $start_date_name = $element['#start_date_element']['#name'] . '[date]';
    }

    $element['repeat'] += [
      '#type' => 'checkbox',
      '#title' => t('Repeat'),
      '#default_value' => $repeat,
      '#attributes' => [
        'class' => ['rrule-repeat'],
      ],
      '#states' => [],
    ];

    if (isset($start_date_name)) {
      $element['repeat']['#attributes']['data-drupal-date-recurring-start-date-name'] = $start_date_name;

      $element['repeat']['#states'] = [
        'disabled' => [
          ':input[name="' . $start_date_name . '"]' => ['value' => ''],
        ],
      ];
    }

    $element['freq'] += [
      '#type' => 'select',
      '#title' => t('Repeat'),
      '#options' => [
        'YEARLY' => t('yearly'),
        'MONTHLY' => t('monthly'),
        'WEEKLY' => t('weekly'),
        'DAILY' => t('daily'),
        'HOURLY' => t('hourly'),
        'MINUTELY' => t('minutely'),
        'SECONDLY' => t('secondly'),
      ],
      '#default_value' => isset($parts['FREQ']) ? $parts['FREQ'] : 'WEEKLY',
      '#attributes' => [
        'class' => ['rrule-freq'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => ['checked' => TRUE, 'enabled' => TRUE],
        ],
      ],
    ];

    $element['interval'] += [
      '#type' => 'number',
      '#title' => t('every'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => isset($parts['INTERVAL']) ? $parts['INTERVAL'] : '1',
      '#attributes' => [
        'class' => ['rrule-interval', 'container-inline'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => ['checked' => TRUE, 'enabled' => TRUE],
        ],
      ],
    ];

    if (isset($parts['BYDAY']) && preg_match_all('/,([+-]\\d)*(MO|TU|WE|TH|FR|SA|SU)/', ',' . $parts['BYDAY'], $matches) !== FALSE) {
      $byday = array_unique($matches[2]);
      $byday_pos = array_unique(array_filter($matches[1]));
    }

    $element['byday'] += [
      '#type' => 'checkboxes',
      '#title' => t('On'),
      '#options' => [
        'MO' => t('Monday'),
        'TU' => t('Tuesday'),
        'WE' => t('Wednesday'),
        'TH' => t('Thursday'),
        'FR' => t('Friday'),
        'SA' => t('Saturday'),
        'SU' => t('Sunday'),
      ],
      '#default_value' => $byday,
      '#attributes' => [
        'class' => ['rrule-byday'],
      ],
      '#states' => [
        'visible' => [
          [
            ':input[name="' . $element['#name'] . '[repeat]"]' => ['checked' => TRUE, 'enabled' => TRUE],
            ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'MONTHLY'],
          ],
          'OR',
          [
            ':input[name="' . $element['#name'] . '[repeat]"]' => ['checked' => TRUE, 'enabled' => TRUE],
            ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'WEEKLY'],
          ],
        ],
      ],
    ];
    $byweek_pos_options = [
      '+1' => t('first'),
      '+2' => t('second'),
      '+3' => t('third'),
      '+4' => t('fourth'),
      '+5' => t('fifth'),
      '-1' => t('last'),
    ];
    if (!empty($byday_pos)) {
      $link_items = array_intersect_key($byweek_pos_options, array_flip($byday_pos));
      $last       = array_slice($link_items, -1);
      $first      = implode(', ', array_slice($link_items, 0, -1));
      $both       = array_filter(array_merge(array($first), $last), 'strlen');
      $link_text  = implode(' ' . t('and') . ' ', $both);
    }
    else {
      $link_text = '(' . t("Select a week") . ')';
    }

    $element['pos'] += [
      '#type' => 'checkboxes',
      '#title' => t('the'),
      '#options' => $byweek_pos_options,
      '#default_value' => $byday_pos,
      '#attributes' => [
        'class' => ['rrule-byday-pos'],
      ],
      '#link_text' => $link_text,
      '#theme_wrappers' => ['rrule_byday_pos_wrapper'],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'MONTHLY'],
        ],
      ],
    ];

    $element['bymonth'] += [
      '#type' => 'checkboxes',
      '#title' => t('Only in'),
      '#options' => [
        1 => t('Jan'),
        2 => t('Feb'),
        3 => t('Mar'),
        4 => t('Apr'),
        5 => t('May'),
        6 => t('Jun'),
        7 => t('Jul'),
        8 => t('Aug'),
        9 => t('Sep'),
        10 => t('Oct'),
        11 => t('Nov'),
        12 => t('Dec'),
      ],
      '#attributes' => [
        'class' => ['rrule-bymonth', 'container-inline'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'MONTHLY'],
        ],
      ],
    ];

    $element['byhour'] += [
      '#type' => 'textfield',
      '#title' => t('Only at'),
      '#default_value' => isset($parts['BYHOUR']) ? $parts['BYHOUR'] : NULL,
      '#size' => 15,
      '#field_suffix' => t('hours'),
      '#attributes' => [
        'class' => ['rrule-byhour', 'container-inline'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'HOURLY'],
        ],
      ],
    ];

    $element['byminute'] += [
      '#type' => 'textfield',
      '#title' => t('Only at'),
      '#default_value' => isset($parts['BYMINUTE']) ? $parts['BYMINUTE'] : NULL,
      '#size' => 15,
      '#field_suffix' => t('minutes'),
      '#attributes' => [
        'class' => ['rrule-byminute', 'container-inline'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'MINUTELY'],
        ],
      ],
    ];

    $element['bysecond'] += [
      '#type' => 'textfield',
      '#title' => t('Only at'),
      '#default_value' => isset($parts['BYSECOND']) ? $parts['BYSECOND'] : NULL,
      '#size' => 15,
      '#field_suffix' => t('seconds'),
      '#attributes' => [
        'class' => ['rrule-bysecond', 'container-inline'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[freq]"]' => ['value' => 'SECONDLY'],
        ],
      ],
    ];

    $value = 0;
    if (isset($parts['COUNT']) && $parts['COUNT']) {
      $value = 1;
    }
    elseif (isset($parts['UNTIL'])) {
      $value = 2;
    }
    $element['end'] += [
      '#type' => 'radios',
      '#title' => t('end'),
      '#options' => [
        t('Never'),
        t('After'),
        t('On date'),
      ],
      '#default_value' => $value,
      '#attributes' => [
        'class' => ['rrule-end'],
      ],
      '#theme' => 'rrule_end',
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
        ],
      ],
    ];

    $element['count'] += [
      '#type' => 'number',
      '#title' => t('occurrences'),
      '#title_display' => 'after',
      '#default_value' => isset($parts['COUNT']) ? $parts['COUNT'] : NULL,
      '#attributes' => [
        'class' => ['rrule-count'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[end]"]' => ['value' => '1'],
        ],
      ],
    ];
    $date_until = NULL;
    if (isset($parts['UNTIL'])) {
      $date_until = DrupalDateTime::createFromDateTime($parts['UNTIL']);
    }

    $element['until'] += [
      '#type' => 'datetime',
      '#value' => $date_until instanceof DateTimePlus ? [
        'date' => $date_until->format(DateFormat::load('html_date')
          ->getPattern()),
        'time' => $date_until->format(DateFormat::load('html_time')
          ->getPattern()),
        'object' => $date_until,
      ] : NULL,
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
      '#attributes' => [
        'class' => ['rrule-util'],
      ],
      '#element_validate' => [
        ['\Drupal\date_recur\Element\RRule', 'validateUntilDate'],
        ['\Drupal\Core\Datetime\Element\Datetime', 'validateDatetime'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
          ':input[name="' . $element['#name'] . '[end]"]' => ['value' => '2'],
        ],
      ],
    ];
  
    $date_excluded = [];
    if (isset($parts['EXDATE'])) {
      $count = 0;
      foreach($parts['EXDATE'] as $exdate) {
        $date_excluded[$count] = DateTimePlus::createFromFormat(DateRecurRRule::RFC_DATE_FORMAT, $exdate);
        $count++;
      }
    }
  
    // Load from the formstate also.
    /**
    if ($form_state->isSubmitted()) {
      $submit_element = $form_state->getTriggeringElement();
      array_pop($submit_element['#parents']);
      $element_values = NestedArray::getValue($form_state->getUserInput(), $submit_element['#parents']);
      $count = 0;
      foreach ($element_values as $element_item) {
        if (isset($element_item['date']) && !empty($element_item['date'])) {
          $date_exclude[$count] = DateTimePlus::createFromFormat('Y-m-d', $element_item['date']);
        }
        $count++;
      }
    }
    */

    $exdate_count = $form_state->get('exdate_count');
    if (empty($exdate_count)) {
      $exdate_count = 1;
      $form_state->set('exdate_count', $exdate_count);
    }

    $element['exdate'] += [
      '#type' => 'container',
      '#prefix' => '<div id="exdate-wrapper">',
      '#suffix' => '</div>',
      '#weight' => 99,
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
        ],
      ],
    ];

    for ($i = 0; $i < $exdate_count; $i++) {
      // Fetch the values from the element values if they were previously set.
      /**
      if (isset($element['#value']['exdate']['exclude_date_' . $i])) {
        $excluded_date = $element['#value']['exdate']['exclude_date_' . $i];
        if (!empty($excluded_date['date'])) {
          $date_exclude[$i] = DateTimePlus::createFromFormat('Y-m-d', $excluded_date['date']);
        }
      }
      */

      $default_date = $form_state->getvalues()['field_schedule_date_recur'][0]['rrule']['exdate']['exclude_date_' . $i];
      if (isset($default_date['date']) && !empty($default_date['date'])) {
        $date_excluded[$i] = DateTimePlus::createFromFormat('Y-m-d', $default_date['date']);
      }

      // Exclude date
      $element['exdate']['exclude_date_' . $i] = [
        '#type' => 'datetime',
        '#title' => t('Exclude'),
        '#default_value' => $date_excluded[$i],
        '#value' => $date_excluded[$i] instanceof DateTimePlus ?
          ['date' => $date_excluded[$i]->format(DateFormat::load('html_date')->getPattern()),
          'object' => $date_excluded[$i],] : NULL,

   /**
        '#value' => $date_exclude[$i] instanceof DateTimePlus ? 
          ['date' => $date_exclude[$i]->format(DateFormat::load('html_date')->getPattern()),
          'object' => $date_exclude[$i],] : NULL,*/
        '#date_date_element' => 'date',
        '#date_time_element' => 'none',
        '#attributes' => [
          'class' => ['rrule-exdate'],
        ],
        '#element_validate' => [
          //['\Drupal\date_recur\Element\RRule', 'validateUntilDate'],
          //['\Drupal\Core\Datetime\Element\Datetime', 'validateDatetime'],
        ],
        '#states' => [
          'visible' => [
            ':input[name="' . $element['#name'] . '[repeat]"]' => [
              'checked' => TRUE,
              'enabled' => TRUE,
            ],
            ':input[name="' . $element['#name'] . '[end]"]' => ['value' => '2'],
          ],
        ],
      ];
    }

    $element['exdate']['add_item'] = [
      '#type' => 'submit',
      '#value' => t('Add Another Exclude date'),
      '#submit' => ['Drupal\date_recur\Element\RRule::date_recur_add_exlude_add_item'],
      '#ajax' => [
        'callback' => 'Drupal\date_recur\Element\RRule::date_recur_exclude_date_ajax_callback',
        'wrapper' => 'exdate-wrapper',
      ],
    ];

    $element['summary'] += [
      '#type' => 'item',
      '#title' => t('Summary:'),
      '#markup' => '<em class="rrule-summary">' . $rrule->humanReadable() . '</em>',
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
        ],
      ],
    ];
    $element['rrule'] += [
      '#type' => 'item',
      '#title' => t('RRule:'),
      '#markup' => '<code class="rrule-code">' . $rrule->getRRule() . '</code>',
      '#states' => [
        'visible' => [
          ':input[name="' . $element['#name'] . '[repeat]"]' => [
            'checked' => TRUE,
            'enabled' => TRUE,
          ],
        ],
      ],
    ];

    return $element;
  }

  /**
   * Ajax add item callback function to add another exclude date.
   *
   * @param form $form 
   *   Form element for	the page.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  function date_recur_exclude_date_ajax_callback(&$form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    // Get the parent of the triggering element, this should be the container.
    array_pop($element['#array_parents']);
    $items = NestedArray::getValue($form, $element['#array_parents']);
    return $items;
  }

  /**
   * Ajax add item callback function to add another exclude date.
   *
   * @param form $form
   *   Form element for the page.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  function date_recur_add_exlude_add_item(&$form, FormStateInterface $form_state) {
    $exdate_count = $form_state->get('exdate_count');
    $form_state->set('exdate_count', ($exdate_count + 1));
    $form_state->setRebuild();
  }


  /**
   * Validate the until date.
   *
   * @param array $element
   *   The form element to be validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form.
   *
   * @todo There seems to be an issue where the object is not being saved to the datetime field. I am not sure if this is an issue with datetime_tweaks module or some other weird thing.
   */
  public static function validateUntilDate(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $input_exists = FALSE;
    $input = NestedArray::getValue($form_state->getValues(), $element['#parents'], $input_exists);
    if ($input_exists && !isset($input['object'])) {
      $value = call_user_func_array($element['#value_callback'], [
        &$element,
        &$input,
        &$form_state,
      ]);
      $form_state->setValue($element['#parents'], $value);
    }
  }

  /**
   * Get the RRule for a form element.
   *
   * @param array $element
   *   The form element.
   * @param array $value
   *   The form value.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return string
   *   The RRule definition.
   */
  public static function getRRule(array $element, array $value, FormStateInterface $form_state) {
    if (isset($value['repeat']) && $value['repeat']) {
      // Fetch the RRule parts.
      $parts = self::buildParts($element, $value, $form_state);

      $set_keys = ['RDATE', 'EXRULE', 'EXDATE'];

      array_walk($parts, function (&$v, $k) {
        $v = $k . '=' . $v;
      });

      $return = 'RRULE:' . implode(';', $parts);

      // Fetch the set parts.
      $set_parts = self::buildSetParts($element, $value, $form_state);

      array_walk($set_parts, function (&$v, $k) {
        if (is_array($v)) {
          $return = $k . ':';
          foreach ($v as $index => $value) {
            $return .= $value; 
          }
          $v = $return;
        }
        else {
          $v = $k . ':' . $v;
        }
      });
      // Appeand the set parts on new lines. 
      foreach ($set_keys as $set_key) {
        if (isset($set_parts[$set_key])) {
          $return .= "\n" . $set_parts[$set_key];
        }
      }
      return $return;
    }

    return '';
  }

  /**
   * Build an RRule from its Parts.
   *
   * @param array $element
   *   The form element.
   * @param array $input
   *   The input parts.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The RRule definition array.
   */
  protected static function buildParts(array $element, array $input, FormStateInterface $form_state) {
    $parts = array_change_key_case(array_intersect_key($input, array_flip([
      'freq',
      'interval',
      'byday',
      'byhour',
      'byminute',
      'bysecond',
    ])), CASE_UPPER);

    // Don't include the INTERVAL if it is only set to 1, this is implied.
    if ($parts['INTERVAL'] == '1') {
      unset($parts['INTERVAL']);
    }
    if (isset($input['pos'])
      && isset($parts['BYDAY'])
      && is_array($input['pos'])
      && is_array($parts['BYDAY'])
    ) {
      $pos = array_filter($input['pos']);
      if (!empty($pos)) {
        $new_pos = [];

        foreach (array_filter($parts['BYDAY']) as $day) {
          foreach ($pos as $p) {
            $new_pos[] = $p . $day;
          }
        }

        $parts['BYDAY'] = array_unique($new_pos);
      }
    }
    if (isset($parts['BYDAY'])
      && is_array($parts['BYDAY'])
      && !empty($parts['BYDAY'])
    ) {
      $parts['BYDAY'] = implode(',', array_filter($parts['BYDAY']));
    }

    switch ($input['end']) {
      case '1':
        $parts['COUNT'] = $input['count'];
        break;

      case '2':
        if (isset($input['until'])) {
          $date_until = $input['until'];
          if ($date_until instanceof DateTimePlus) {
            $parts['UNTIL'] = $date_until->format(DateRecurRRule::RFC_DATE_FORMAT);
          }
        }
    }

    return array_filter($parts);
  }

  /**
   * Fetch the set parts from RRule.
   *
   * @param array $element
   *   The form element.
   * @param array $input
   *   The input parts.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The RRule definition array.
   */
  protected static function buildSetParts(array $element, array $input, FormStateInterface $form_state) {
    $set_parts = [];
    if (isset($input['exdate'])) {
      foreach($input['exdate'] as $input_exdate) {
        if ($input_exdate instanceof DateTimePlus) {
          $set_parts['EXDATE'][] = $input_exdate->format(DateRecurRRule::RFC_DATE_FORMAT);
        }
        elseif (is_array($input_exdate) && isset($input_exdate['date']) && !empty($input_exdate['date'])) {
          $input_date = DateTimePlus::createFromFormat('Y-m-d\TH:i:s', ($input_exdate['date'] . "T00:00:00"));
          $set_parts['EXDATE'][] = $input_date->format(DateRecurRRule::RFC_DATE_FORMAT);
        }        
      }
    }
    return $set_parts;
  }
}
