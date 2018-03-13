<?php

namespace Drupal\date_recur;

use Drupal\Core\Datetime\DateHelper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use RRule\RRule;

class DateRecurDefaultRRule extends RRule {

  protected $timezoneOffset;

  public function getStartDate() {
    if (!empty($this->dtstart)) {
      return $this->dtstart;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Set a timezone offset to add to all dates for display.
   *
   * @param int $offset Timezone offset in seconds.
   */
  public function setTimezoneOffset($offset) {
    $this->timezoneOffset = $offset;
  }

  /**
   * Return a human readable and translated representation of the repeat rule.
   *
   * This is the start for a Drupal-translated and improved converter. So far
   * only handles a few cases. For unhandled cases, this calls back to
   * RRule\RRule::humanReadable.
   *
   * @todo: Cover more (all) cases.
   *
   * @param array $opt
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   */
  public function humanReadable(array $opt = []) {
    $build = [];

    $daynames = DateHelper::weekDays(TRUE);
    // Move Sunday to the end of the week as day 7.
    array_push($daynames, $daynames[0]);
    unset($daynames[0]);
    $monthnames = DateHelper::monthNames(TRUE);

    $parts = [];

    if (!empty($this->byweekday)) {
      $dayparts = [];
      foreach ($this->byweekday as $day) {
        $dayparts[] = $daynames[$day];
      }
      $parts['day'] = $this->_hrFormatList($dayparts);
    }
    elseif (!empty($this->byweekday_nth)) {
      $dayparts = $days = [];
      foreach ($this->byweekday_nth as $info) {
        $days[$info[0]][] = $info[1];
      }
      foreach ($days as $day => $pos) {
        $dayparts[] = $this->t('pos_day', [
          '@pos' => $this->_hrFormatPosList($pos),
          '@day' => $daynames[$day],
        ]);
      }
      $parts['day'] = $this->_hrFormatList($dayparts);
    }
    switch ($this->freq) {
      case self::WEEKLY:
        $build['rule'] = $this->t('weekly', ['@day' => $parts['day']]);
        break;

      case self::MONTHLY:
        $build['rule'] = $this->t('monthly', ['@posday' => $parts['day']]);
        break;
    }

    if (!empty($this->dtstart)) {
      $build['time'] = $this->formatDateForDisplay($this->dtstart, 'H:i');
    }


    if (!empty($build['rule'])) {
      return $this->t('complete', ['@rule' => $build['rule'], '@time' => $build['time']]);
    }
    else {
      $string = parent::humanReadable($opt);
      return $string;
    }

  }

  protected function getString($string) {
    /** @var TranslatableMarkup[] $strings */
    $strings = [
      'complete' => t('@rule at @time'),
      'and' => t('@a and @b'),
      'daily' => t('Every day'),
      'weekly' => t('Every week on @day'),
      'monthly' => t('On the @posday each month'),
      'pos_day' => t('@pos @day'),
      'week_1' => t('first'),
      'week_2' => t('second'),
      'week_3' => t('third'),
      'week_4' => t('forth'),
      'week_5' => t('fifth'),
      'last_1' => t('last'),
      'last_2' => t('second to last'),
    ];

    if (!empty($strings[$string])) {
      return $strings[$string]->getUntranslatedString();
    }
    else {
      return '';
    }
  }

  protected function _hrFormatPosList($list) {
    // Sort like this: 1, 2, 3, 4, 5, -1, -2, -3.
    usort($list, function($a, $b) {
      if ($a >= 0 && $b >= 0) {
        return $a > $b;
      }
      else if ($a >= 0 && $b < 0) {
        return -1;
      }
      else {
        return 1;
      }
     });
    // Append a dot for positive, get a string for ultimates.
    $list = array_map(function($i) {
      if ($i > 0 && $i <= 5) {
        return $this->getString('week_' . abs($i));
      }
      elseif ($i > 0) {
        return $i . '.';
      }
      elseif ($i > -3) {
        return $this->getString('last_' . abs($i));
      }
      else {
        return $i;
      }
    }, $list);
    // Format as list.
    return $this->_hrFormatList($list);
  }

  /**
   * Format a variable-length list into a sentence style string.
   *
   * Like this, for a list with 4 items: A, B, C and D
   * Or with 2 items: A and B
   *
   * @param string[] $list
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected function _hrFormatList($list) {
    if (is_string($list)) {
      return $list;
    }
    if (count($list) == 1) {
      return $list[0];
    }
    else {
      $args['@b'] = array_pop($list);
      $args['@a'] = implode(', ', $list);
      return $this->t('and', $args);
    }
  }

  protected function t($string, $args) {
    return t($this->getString($string), $args);
  }

  protected function formatDateForDisplay(\DateTime $date, $format = 'H:i') {
    if (empty($this->timezone)) {
      return $date->format($format);
    }
    return $date->setTimezone(new \DateTimeZone($this->timezone))->format($format);
  }
}
