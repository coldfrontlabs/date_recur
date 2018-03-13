<?php

namespace Drupal\date_recur;

/**
 * This class handles all RRule related calculations. It calls out to
 * DateRecurDefaultRRule for actual calculations, so that this can possibly
 * be made pluggable for other implementations.
 *
 * @todo:
 * - Load occurrences from database cache instead of recalculating for each
 *   view (or at least benchmark this for different rules).
 * - Properly document and add an interface.
 * - Properly set cache tags, either here or in the formatter.
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Datetime\DateTimePlus;
use RRule\RRule;
use RRule\RfcParser;
use RRule\RSet;


class DateRecurRRule implements \Iterator {

  const RFC_DATE_FORMAT = 'Ymd\THis\Z';

  /**
   * @var \Drupal\date_recur\DateRecurDefaultRSet
   */
  protected $rrule;

  /**
   * @var \DateTime
   */
  protected $startDate;
  protected $startDateEnd;

  /**
   * @var string
   */
  protected $recurTime;

  /**
   * @var \DateInterval
   */
  protected $recurDiff;

  /**
   * @var string
   */
  protected $originalRuleString;

  /**
   * @var array
   */
  protected $parts;

  /**
   * @var array
   */
  protected $setParts;

  /**
   * @var array
   */
  protected $occurrences;

  protected $timezone;
  protected $timezoneOffset;

  /**
   * @param string $rrule The repeat rule.
   * @param \DateTime|DrupalDateTime $startDate The start date (DTSTART).
   * @param \DateTime|DrupalDateTime|NULL $startDateEnd Optionally, the initial event's end date.
   * @throws \InvalidArgumentException
   */
  public function __construct($rrule, $startDate, $startDateEnd = NULL, $timezone = NULL) {
    $this->originalRuleString = $rrule;
    $this->startDate = $startDate;
    $this->recurTime = $this->startDate->format('H:i');
    if (empty($startDateEnd)) {
      $startDateEnd = clone $startDate;
    }
    $this->startDateEnd = $startDateEnd;
    $this->recurDiff = $this->startDate->diff($startDateEnd);

    $this::parseRrule($rrule, $startDate);

    $this->rrule = new DateRecurDefaultRSet();
    $this->rrule->addRRule(new DateRecurDefaultRRule($this->parts));
    if (!empty($this->setParts)) {
      foreach ($this->setParts as $type => $type_parts) {
        foreach ($type_parts as $part) {
          list(, $part) = explode(':', $part);
          switch ($type) {
            case 'RDATE':
              $this->rrule->addDate($part);
              break;
            case 'EXDATE':
              $this->rrule->addExDate($part);
              break;
            case 'EXRULE':
              $this->rrule->addExRule($part);
          }
        }
      }
    }

    if ($timezone) {
      $this->timezone = $timezone;
      $start = clone $this->startDate;
      $start->setTimezone(new \DateTimeZone($this->timezone));
      $this->timezoneOffset = $start->getOffset();
      $this->rrule->setTimezoneOffset($this->timezoneOffset);
    }
  }

  public function getTimezoneOffset() {
    if (!empty($this->timezoneOffset)) {
      return $this->timezoneOffset;
    }
    return FALSE;
  }

  /**
   * return the current rule.
   */
  public function getRRule() {
    $rules = 'RRULE:';
    foreach (explode("\n", $this->rrule->getRRules()[0]) as $line) {
      list($type, $rule) = explode(':', $line);
      if ($type == 'RRULE') {
        $rules .= $rule;
      }
    }

    $set_keys = ['RDATE', 'EXRULE', 'EXDATE'];
    $set_parts = $this->setParts;

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
        $rules .= "\n" . $set_parts[$set_key];
      }
    }
    return $rules;
  }

  public function getParts() {
    return $this->parts;
  }
  
  public function getSetParts() {
    return $this->setParts;
  }


  /**
   * Parse an RFC rrule string and add a start date (DTSTART).
   *
   * @param string $rrule
   * @param \DateTime|DrupalDateTime $startDate
   * @throws \InvalidArgumentException
   * @return array An array of rrule parts.
   */
  public function parseRrule($rrule, $startDate, $check_only = FALSE) {
    // Correct formatting.
    if (strpos($rrule, "\n") === FALSE && strpos($rrule, 'RRULE:') !== 0) {
      $rrule = "RRULE:$rrule";
    }
    $dtstart = 'DTSTART:' . $startDate->format(self::RFC_DATE_FORMAT);

    // Check for unsupported parts.
    $set_keys = ['RDATE', 'EXRULE', 'EXDATE'];
    $rules = $set_parts = [];
    foreach (explode("\n", $rrule) as $key => $part) {
      $els = explode(':', $part);
      if (in_array($els[0], $set_keys)) {
        $exploded_keys = explode(':', $part);
        $date = DateTimePlus::createFromFormat(DateRecurRRule::RFC_DATE_FORMAT, $exploded_keys[1]);
        //$set_parts[$exploded_keys[0]][] = $date;
        $set_parts[$exploded_keys[0]][] = $exploded_keys[1];
      }
      else if ($els[0] == 'RRULE') {
        $rules[] = $part;
      }
      else if ($els[0] == 'DTSTART') {
        $dtstart = $part;
      }
      else {
        throw new \InvalidArgumentException("Unsupported line: " . $part);
      }
    }
    if (!count($rules)) {
      throw new \InvalidArgumentException("Missing RRULE line: " . $rrule);
    }
    if (count($rules) > 1) {
      throw new \InvalidArgumentException("More than one RRULE line is not supported.");
    }
    $rrule = $dtstart . "\n" . $rules[0];

    if (empty($parts['WKST'])) {
      $parts['WKST'] = 'MO';
    }
    $this->parts = RfcParser::parseRRule($rrule);
    $this->setParts = $set_parts;
  }

  /**
  /**
   * Validate that an rrule string is parseable.
   *
   * @param string $rrule
   * @param \DateTime|DrupalDateTime $startDate
   * @throws \InvalidArgumentException
   * @return bool
   */
  public static function validateRule($rrule, $startDate) {
    $rule = new self($rrule, $startDate);
    return TRUE;
  }


  /**
   * Get occurrences, optionally limited by a start date, end date and count.
   *
   * @param null|\DateTime $start
   * @param null|\DateTime $end
   * @param null|int $num
   * @return array
   */
  public function getOccurrences($start = NULL, $end = NULL, $num = NULL) {
    return $this->createOccurrences($start, $end, $num);
  }

  /**
   * Get occurrences between a start and an end date.
   *
   * @param \DateTime|DrupalDateTime $start
   * @param \DateTime|DrupalDateTime $end
   * @return array
   */
  public function getOccurrencesBetween($start, $end) {
    return $this->getOccurrences($start, $end);
  }

  /**
   * Get the next occurrences after a start date.
   *
   * @param \DateTime|DrupalDateTime $start
   * @param int $num
   * @return array
   */
  public function getNextOccurrences($start, $num) {
    return $this->getOccurrences($start, NULL, $num);
  }

  /**
   * Check if the rule is infinite.
   *
   * @return bool
   */
  public function isInfinite() {
    return $this->rrule->isInfinite();
  }

  /**
   * Get the occurrences for storage in the cache table (for views).
   *
   * @see DateRecurFieldItemList::postSave()
   *
   * @param \DateTime $until For infinite dates create until that date.
   * @param string $storageFormat The desired date format.
   * @return array
   */
  public function getOccurrencesForCacheStorage(\DateTime $until, $storageFormat) {
    $occurrences = [];
    if (!$this->rrule->isInfinite()) {
      $occurrences += $this->createOccurrences(NULL, NULL, NULL, FALSE);
    }
    else {
      $occurrences += $this->createOccurrences(NULL, $until, NULL, FALSE);
    }

    foreach ($occurrences as &$row) {
      foreach ($row as $key => $date) {
        if (!empty($date)) {
          $row[$key] = self::massageDateValueForStorage($date, $storageFormat);
        }
      }
    }

    return $occurrences;
  }

  /**
   * @param null|\DateTime $start
   * @param null|\DateTime $end
   * @param null|int $num
   * @return array
   */
  protected function createOccurrences($start = NULL, $end = NULL, $num = NULL, $display = TRUE) {
    if ($this->rrule->isInfinite() && $end === NULL && $num === NULL) {
      throw new \LogicException('Cannot get all occurrences of an infinite recurrence rule.');
    }

    $occurrences = [];
    foreach ($this->rrule as $occurrence) {
      if ($start !== NULL && $occurrence < $start) {
        continue;
      }
      if ($end !== NULL && $occurrence > $end) {
        break;
      }
      if ($num !== NULL && count($occurrences)  >= $num) {
        break;
      }
      $occurrences[] = $this->massageOccurrence($occurrence, $display);
    }

    return $occurrences;
  }

  /**
   * @param \DateTime $occurrence
   * @param bool|TRUE $display
   * @return array[[value => DrupalDateTime, end_value => DrupalDateTime], ...]
   */
  protected function massageOccurrence(\DateTime $occurrence, $display = TRUE) {
    /** @var DateTimePlus $date */
    $date = DrupalDateTime::createFromFormat('Ymd H:i', $occurrence->format('Ymd') . ' ' . $this->recurTime, $this->startDate->getTimezone());
    if ($display) {
      $date = $this->adjustDateForDisplay($date);
    }

    $date_end = clone $date;
    if (!empty($this->recurDiff)) {
      $date_end = $date_end->add($this->recurDiff);
    }
    return ['value' => $date, 'end_value' => $date_end];
  }

  /**
   * @param $date
   * @return \DateTime $date
   */
  public function adjustDateForDisplay($date) {
    if (empty($this->timezone)) {
      return $date;
    }
    return $date->setTimezone(new \DateTimeZone($this->timezone));
  }

  public static function massageDateValueForStorage($date, $format) {
    if (is_string($date)) {
      if ($date == "now") {
       $date = date("Y-m-d\TH:i:s");
      }
      $date = date_create_from_format('Y-m-d\TH:i:s',$date);
    }
    if ($format == DATETIME_DATE_STORAGE_FORMAT) {
      datetime_date_default_time($date);
    }
    $date->setTimezone(new \DateTimeZone(DATETIME_STORAGE_TIMEZONE));
    // Adjust the date for storage.
    return $date->format($format);
  }

  public function getWeekdays() {

    $weekdays = [];
    $byday = $this->parts['BYDAY'];
    if (!is_array($byday) ) {
      $byday = explode(',', $byday);
    }

    $this->byweekday = array();
    $this->byweekday_nth = array();
    foreach ($byday as $value) {
      $value = trim(strtoupper($value));
      $valid = preg_match('/^([+-]?[0-9]+)?([A-Z]{2})$/', $value, $matches);

      if (!empty($matches[2])) {
        $day = RRule::$week_days[$matches[2]];
        $weekdays[$day] = $day;
      }
    }
    return $weekdays;

    if (!empty($this->parts['BYDAY'])) {
      $days = explode(',', $this->parts['BYDAY']);
      foreach ($days as $day) {
        $day = preg_replace('/[\+\-0-9]*/', '', $day);
        $weekdays[$day] = $day;
      }
    }
    return $weekdays;
  }

  /**
   * Get a human-readable representation of the repeat rule.
   *
   * @todo: Make this translatable.
   *
   * @return string
   */
  public function humanReadable() {
    return $this->rrule->humanReadable();
  }

  /**
   * Return the current element
   */
  public function current() {
    if ($date = $this->rrule->current()) {
      return $this->massageOccurrence($date);
    }
  }

  /**
   * Move forward to next element
   */
  public function next() {
    if ($date = $this->rrule->next()) {
      return $this->massageOccurrence($date);
    }
  }

  /**
   * Return the key of the current element
   */
  public function key() {
    return $this->rrule->key();
  }

  /**
   * Checks if current position is valid
   */
  public function valid() {
    return $this->rrule->valid();
  }

  /**
   * Rewind the Iterator to the first element
   */
  public function rewind() {
    $this->rrule->rewind();
  }
}
