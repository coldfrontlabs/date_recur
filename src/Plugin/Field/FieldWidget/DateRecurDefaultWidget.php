<?php

namespace Drupal\date_recur\Plugin\Field\FieldWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\date_recur\DateRecurRRule;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use RRule\RRule;

/**
 * Plugin implementation of the 'date_recur_default_widget' widget.
 *
 * @FieldWidget(
 *   id = "date_recur_default_widget",
 *   label = @Translation("Date recur default widget"),
 *   field_types = {
 *     "date_recur"
 *   }
 * )
 */
class DateRecurDefaultWidget extends DateRangeDefaultWidget {

  protected $timezone;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'timezone_override' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['timezone_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Time zone override'),
      '#description' => $this->t('The time zone selected here will always be used when interpreting the dates inserted in the widget. If empty, the user\'s timezone will be used.'),
      '#options' => system_time_zones(TRUE),
      '#default_value' => $this->getSetting('timezone_override'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($override = $this->getSetting('timezone_override')) {
      $summary[] = $this->t('Time zone: @timezone', ['@timezone' => $override]);
    }
    return $summary;
  }

  public function getTimezone() {
    if ($this->getSetting('timezone_override')) {
      return $this->getSetting('timezone_override');
    }
    else {
      return drupal_get_user_timezone();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    unset($element['#title'], $element['#title_display']);
    $element['#element_validate'][] = [$this, 'validateRrule'];

    $element['end_value']['#required'] = FALSE;

    $element['rrule'] = [
      '#type' => 'textarea',
      '#default_value' => isset($items[$delta]->rrule) ? $items[$delta]->rrule : NULL,
      '#title' => $this->t('Repeat rule (RRULE)'),
    ];
    return $element;
  }

  /**
   * Creates a date object for use as a default value.
   *
   * This overrides DateRangeWidgetBase to change timezone override.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime $date
   * @param string $timezone
   * @return \Drupal\Core\Datetime\DrupalDateTime
   */
  protected function createDefaultValue($date, $timezone) {
    // The date was created and verified during field_load(), so it is safe to
    // use without further inspection.
    if ($this->getFieldSetting('datetime_type') == DateTimeItem::DATETIME_TYPE_DATE) {
      // A date without time will pick up the current time, use the default
      // time.
      datetime_date_default_time($date);
    }
    $date->setTimezone(new \DateTimeZone($this->getTimezone()));
    return $date;
  }

  /**
   * #element_validate callback to validate the repeat rule.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   generic form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   */
  public function validateRrule(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if (!empty($element['rrule']['#value']) && $element['value']['#value']['object'] instanceof DrupalDateTime) {
      try {
        DateRecurRRule::validateRule($element['rrule']['#value'], $element['value']['#value']['object']);
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setError($element, $this->t('Invalid repeat rule: %message', ['%message' => $e->getMessage()]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$item) {
      $item['infinite'] = 0;
      if (empty($item['rrule'])) {
        $item['rrule'] = '';
      }
      else {
        if (!empty($item['value']) && $item['value'] instanceof DrupalDateTime) {
          try {
            $rule = new DateRecurRRule($item['rrule'], $item['value']);
            if ($rule->isInfinite()) {
              $item['infinite'] = 1;
            }
          } catch (\InvalidArgumentException $e) {
            // No-op, this is handled in validateRrule().
          }
        }
      }
      $item['timezone'] = $this->getTimezone();
      if (empty($item['end_value'])) {
        $item['end_value'] = $item['value'];
      }
    }
    return parent::massageFormValues($values, $form, $form_state);
  }
}
