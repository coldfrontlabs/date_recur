/**
 * @file
 * Date Recur Rrule Editor
 */

(function ($, Drupal, RRule, moment) {

  'use strict';

  // Add helpful constants to RRule.
  RRule.FREQUENCY_ADVERBS = [
    Drupal.t('yearly', {}, {context: 'Date recur: Freq'}),
    Drupal.t('monthly', {}, {context: 'Date recur: Freq'}),
    Drupal.t('weekly', {}, {context: 'Date recur: Freq'}),
    Drupal.t('daily', {}, {context: 'Date recur: Freq'}),
    Drupal.t('hourly', {}, {context: 'Date recur: Freq'}),
    Drupal.t('minutely', {}, {context: 'Date recur: Freq'}),
    Drupal.t('secondly', {}, {context: 'Date recur: Freq'})
  ];

  RRule.FREQUENCY_CODES = [
    'YEARLY',
    'MONTHLY',
    'WEEKLY',
    'DAILY',
    'HOURLY',
    'MINUTELY',
    'SECONDLY'
  ];

  RRule.FREQUENCY_NAMES = [
    Drupal.t('year'),
    Drupal.t('month'),
    Drupal.t('week'),
    Drupal.t('day'),
    Drupal.t('hour'),
    Drupal.t('minute'),
    Drupal.t('second')
  ];

  RRule.FREQUENCY_NAMES_PLURAL = [
    Drupal.t('years'),
    Drupal.t('months'),
    Drupal.t('weeks'),
    Drupal.t('days'),
    Drupal.t('hours'),
    Drupal.t('minutes'),
    Drupal.t('seconds')
  ];

  RRule.DAYCODES = [
    'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'
  ];

  RRule.DAYNAMES = [
    Drupal.t('Monday'),
    Drupal.t('Tuesday'),
    Drupal.t('Wednesday'),
    Drupal.t('Thursday'),
    Drupal.t('Friday'),
    Drupal.t('Saturday'),
    Drupal.t('Sunday')
  ];

  RRule.MONTHS = [
    Drupal.t('Jan'),
    Drupal.t('Feb'),
    Drupal.t('Mar'),
    Drupal.t('Apr'),
    Drupal.t('May'),
    Drupal.t('Jun'),
    Drupal.t('Jul'),
    Drupal.t('Aug'),
    Drupal.t('Sep'),
    Drupal.t('Oct'),
    Drupal.t('Nov'),
    Drupal.t('Dec')
  ];

  RRule.POSCODES = [
    '+1', '+2', '+3', '+4', '+5', '-1'
  ];

  RRule.SETPOS = {
    '+1': Drupal.t('first', {}, {context: 'Date recur: Freq'}),
    '+2': Drupal.t('second', {}, {context: 'Date recur: Freq'}),
    '+3': Drupal.t('third', {}, {context: 'Date recur: Freq'}),
    '+4': Drupal.t('forth', {}, {context: 'Date recur: Freq'}),
    '+5': Drupal.t('fifth', {}, {context: 'Date recur: Freq'}),
    '-1': Drupal.t('last', {}, {context: 'Date recur: Freq'}),
  };

  Drupal.behaviors.dateRecur = {
    attach: function (context) {
      $('.byday-pos-text', context).click(function(e) {
        var $parent = $(this).parent();

        if ($(this).hasClass('select-shown')) {
          $(this).removeClass('select-shown');
          $('.byday-pos-input', $parent).hide();
        }
        else {
          $(this).addClass('select-shown');
          $('.byday-pos-input', $parent).show();
        }
        e.stopPropagation();
      });

      $('input.rrule-byday-pos', context).change(function (e) {

        var $parent = $(this).parents('.byday-pos-container');
        var selectedText = [];
        $('.byday-pos-text', $parent).html(Drupal.t('(Select a week)'));
        $('input:checked', $parent).each(function () {
          selectedText.push(RRule.SETPOS[$(this).val()]);
        });

        if (selectedText.length) {
          $('.byday-pos-text', $parent).html([
            [ selectedText.slice(0, -1).join(', ') ],
            selectedText.slice(-1)[0]
          ].join(selectedText.length < 2 ? '' : ' ' + Drupal.t('and') + ' '));
        }
      });

      $('.byday-pos-input', context).click(function(e) {
        e.stopPropagation();
      });

      $(document).on('click.dateRecur', function(e) {
        if ($('.byday-pos-text').hasClass('select-shown')) {
          $('.byday-pos-text').removeClass('select-shown');
          $('.byday-pos-input').hide();
        }
      });

      $('.form-rrule', context).find('.rrule-repeat').each(function (e) {
        var $repeat = $(this);
        var start_date_name = $repeat.attr('data-drupal-date-recurring-start-date-name');
        if (start_date_name != '') {
          $(':input[name="' + start_date_name + '"]').change(function (e) {
            if ($(this).val() == '') {
              $repeat.attr('checked', false);
            }
          });
        }
      });

      $('.form-rrule', context).find('input[class!=".rrule-repeat"], select')
        .change(function (e) {
          var $parent = $(this).parents('.form-rrule').get(0);

          $('.rrule-repeat:checked', $parent).each(function () {
            var values = {};
            var options = {};
            var dateFormats = {};

            $('input, select', $parent).each(function () {
              var k;
              if ($(this).prop('tagName').toLowerCase() === 'input') {
                switch ($(this).attr('type')) {
                  case 'checkbox':
                    if ($(this).prop('checked')) {
                      k = this.name.replace(/^.*\[(.*?)\]\[(.*?)\]$/g, "$1");

                      if (values[k] == undefined) {
                        values[k] = [];
                      }
                      values[k].push($(this).val());
                    }
                    break;

                  // Ignore time, as the date field will combine them.
                  case 'time':
                    break;

                  case 'date':
                    var dateValue = $(this).val();
                    if (dateValue !== '') {
                      // Drupal datestime fields have a [date] appended.
                      k = this.name.replace(/^.*\[(.*?)\]\[(.*?)\]$/g, "$1");
                      var timeValue = $(this).parent().parent()
                        .find('input[type="time"]').val();
                      values[k] = dateValue + ' '
                        + (timeValue === '' ? '00:00' : timeValue) + ' '
                        + window.drupalSettings.date_recur['offset'];

                      // Create the format for Moment and then append the hours
                      // and timezone.
                      var format = $(this).data('drupal-date-format');
                      if (typeof format !== 'undefined') {
                        // Save the date format so we can parse the date better.
                        dateFormats[k] = format
                            .replace('d', 'DD')
                            .replace('m', 'MM')
                            .replace('Y', 'YYYY') + ' kk:mm ZZ';
                      }
                      else {
                        dateFormats[k] = moment.ISO_8601;
                      }
                    }
                    break;
                  case 'submit':
                    // Submit button DO NOTHING.
                    // This will exclude the OP buttons. 
                    break;
                  default:
                    k = this.name.replace(/^.*\[(.*?)\]$/g, "$1");
                    values[k] = $(this).val();
                }
              }
              else {
                k = this.name.replace(/^.*\[(.*?)\]$/g, "$1");
                values[k] = $(this).val();
              }
            });
            
            if (_.has(values, 'pos') && _.has(values, 'byday')) {
              var weekdayPos = _.map(values['pos'], function (i) {
                if (i !== '-1') {
                  return RRule.POSCODES.indexOf(i) + 1;
                }
                else {
                  return -1;
                }
              });
            }

            var getDay = function (dow) {
              var days = ['', RRule.MO, RRule.TU, RRule.WE, RRule.TH, RRule.FR, RRule.SA, RRule.SU];
              var i = RRule.DAYCODES.indexOf(dow) + 1;
              if (typeof weekdayPos !== 'undefined') {
                if (weekdayPos instanceof Array) {
                  return _.map(weekdayPos, function (pos) {
                    return days[i].nth(pos)
                  });
                }
                else {
                  return days[i].nth(weekdayPos);
                }
              }
              return [days[i]];
            };

            var parseDate = function (dateval, dateFormat) {
              var d = new Date(moment(dateval, dateFormat));
              return new Date(d.getTime());
            };

            delete values['rrule'];
            delete values['end'];
            delete values['pos'];

            var k, v;
            var exdate = [];
            for (k in values) {
              v = values[k];
              if (!v) {
                continue;
              }
              if (_.contains(["dtstart", "until"], k)) {
                v = parseDate(v, dateFormats[k]);
              }
              else if (k === 'byday') {
                if (v instanceof Array) {
                  v = _.flatten(_.map(v, getDay), true);
                }
                else {
                  v = getDay(v);
                }
                k = 'byweekday';
              }
              else if (/^by/.test(k)) {
                if (!(v instanceof Array)) {
                  v = _.compact(v.split(/[,\s]+/));
                }
                v = _.map(v, function (n) {
                  return parseInt(n, 10);
                });
              }
              else if (k === 'freq') {
                v = RRule.FREQUENCY_CODES.indexOf(v);
              }
              else {
                v = parseInt(v, 10);
              }
              if (k === 'wkst') {
                v = getDay(v);
              }
              if (/^exclude_date_\d+/.test(k)) {
                // Set date exdate here.
                exdate.push(parseDate(values[k], dateFormats[k]));
                continue;
              }
              if (k === 'interval' && v === 1) {
                continue;
              }
              
              options[k] = v;
            }

            var rule, type, key;
            try {
              rule = new RRule.RRuleSet();
              rule.rrule(new RRule(options));
              // Loop over the exclude dates.
              $.each(exdate, function( index, value ) {
                rule.exdate(value);
              });              
            }
            catch (_error) {
              var e = _error;
              $(".rrule-summary", this.element).html($('<pre class="error"/>').text('=> ' + String(e || null)));
              return;
            }

            if (rule) {
              // TODO fix the human read able with the exclude. 
              var text = rule._rrule[0].toText();
              $('.rrule-summary', $parent).html(text);
              $('.rrule-code', $parent).html(rule.valueOf().join("\n"));
            }
          });
        });
    },
  };

}(jQuery, Drupal, RRule, moment));
