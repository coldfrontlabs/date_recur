# Date Recur (Drupal 8)

Recurring dates, i.e. date repeat, for Drupal 8.

* Provides a new field type that supports repeating dates via repeat rules (RRULE). For RRULE compilation, [php-rrule](https://github.com/rlanvin/php-rrule) is used.
* Provides a simple formatter that can display the next occurences and the repeat rule in human readable form. The latter is still rough (no translation support, many rules look weird) as it is directly taken from php-rrule.
* Provides an interactive widget featuring a dynamic repeat rule entry form. Makes use of [rrule.js](https://github.com/jkbrzt/rrule/) which is included with the module.

Functionality is there and basically works. Misses testing and tests. Several edge cases are not yet covered.

## Status

Depends on Drupal 8.2 and the experimental date_range module.

The field type uses a seperate table per field to store the repeat occurences to not automatically always load them with the entity. If there are many (think hundreds or thousands) occurences, this should preserve sanity.

Views works. Calendar needs to be patched (see https://www.drupal.org/node/2820803).

## Installation

Installation via composer is recommended.  Run the following:

    composer require drupal/date_recur:dev-1.x --prefer-source

Without composer, you have to take care of installing php-rrule yourself (this is unsupported).
