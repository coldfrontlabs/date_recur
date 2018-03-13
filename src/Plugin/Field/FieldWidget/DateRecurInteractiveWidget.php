<?php

namespace Drupal\date_recur\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'date_recur_interactive_widget' widget.
 *
 * @FieldWidget(
 *   id = "date_recur_interactive_widget",
 *   label = @Translation("Date recur interactive widget"),
 *   field_types = {
 *     "date_recur"
 *   }
 * )
 */
class DateRecurInteractiveWidget extends DateRecurDefaultWidget {
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $class = get_class($this);

    $element['rrule']['#type'] = 'rrule';
    $element['rrule']['#start_date_element'] = &$element['value'];
    return $element;
  }

}

