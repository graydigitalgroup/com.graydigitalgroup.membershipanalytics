<?php

use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Central config/helpers for the membership analytics extension: fiscal-year math,
 * status classification, counting statuses, and reportable types.
 *
 * This extension is client-agnostic. Defaults reference CORE CiviCRM only. Sites with
 * custom statuses or dimensions (e.g. site-specific membership statuses, or a boolean
 * custom flag) configure them one of two ways:
 *   1. Another extension overrides programmatically via the Symfony events dispatched
 *      here (civi.membershipanalytics.statusClassification / .historizedDimensions), or
 *   2. Manually, via the corresponding settings after install.
 */
class CRM_Membershipanalytics_Config {

  /**
   * Reporting-year basis options.
   */
  public static function getReportingYearBases(): array {
    return [
      'fiscal' => E::ts('Fiscal Year'),
      'calendar' => E::ts('Calendar Year'),
    ];
  }

  /**
   * Core fiscal-year start as ['M' => int, 'd' => int] (e.g. Nov 1 => [11, 1]).
   */
  public static function fiscalYearStart(): array {
    $raw = \Civi::settings()->get('fiscalYearStart') ?: ['M' => 1, 'd' => 1];
    return ['M' => (int) $raw['M'], 'd' => (int) $raw['d']];
  }

  /**
   * Fiscal year that a date belongs to, labeled by the ending calendar year.
   *
   * With a Nov 1 start, FY2026 spans Nov 1 2025 – Oct 31 2026, so a date on/after
   * Nov 1 rolls into the next year's label. Confirm this labeling per site.
   */
  public static function fiscalYearOf(\DateTimeInterface $date): int {
    $start = self::fiscalYearStart();
    $year = (int) $date->format('Y');
    $month = (int) $date->format('n');
    $day = (int) $date->format('j');
    $onOrAfterStart = ($month > $start['M']) || ($month === $start['M'] && $day >= $start['d']);
    return $onOrAfterStart ? $year + 1 : $year;
  }

  /**
   * Calendar year of a date.
   */
  public static function calendarYearOf(\DateTimeInterface $date): int {
    return (int) $date->format('Y');
  }

  /**
   * The reporting-year (fiscal or calendar) a date belongs to, per the setting.
   */
  public static function reportingYearOf(\DateTimeInterface $date): int {
    $basis = \Civi::settings()->get('membershipanalytics_reporting_year_basis') ?: 'fiscal';
    return $basis === 'calendar' ? self::calendarYearOf($date) : self::fiscalYearOf($date);
  }

  /**
   * Membership type ids that count as "membership" in reports.
   * Empty setting = all active types.
   */
  public static function reportableTypeIds(): array {
    $configured = \Civi::settings()->get('membershipanalytics_reportable_membership_types');
    if (!empty($configured)) {
      return array_map('intval', $configured);
    }
    return \Civi\Api4\MembershipType::get(FALSE)
      ->addSelect('id')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->column('id');
  }

  /**
   * Status ids that count a member as present.
   * Empty setting = all statuses where core is_current_member = 1.
   */
  public static function countingStatusIds(): array {
    $configured = \Civi::settings()->get('membershipanalytics_counting_status_ids');
    if (!empty($configured)) {
      return array_map('intval', $configured);
    }
    return \Civi\Api4\MembershipStatus::get(FALSE)
      ->addSelect('id')
      ->addWhere('is_current_member', '=', TRUE)
      ->execute()
      ->column('id');
  }

  /**
   * Contact dimensions to historize (field keys).
   *
   * Default is CORE demographic fields only. A site adds custom fields via the setting
   * or by listening to the historizedDimensions event.
   */
  public static function historizedDimensions(): array {
    $configured = \Civi::settings()->get('membershipanalytics_historized_dimensions');
    $dimensions = !empty($configured) ? $configured : ['gender', 'country', 'state', 'city'];

    $event = \Civi\Core\Event\GenericHookEvent::create(['dimensions' => &$dimensions]);
    \Civi::dispatcher()->dispatch('civi.membershipanalytics.historizedDimensions', $event);

    return $dimensions;
  }

  /**
   * Default status name => lifecycle bucket mapping for CORE CiviCRM statuses only.
   * Custom statuses are resolved via is_current_member, then overridden by the setting
   * or the statusClassification event.
   */
  public static function defaultStatusBucketsByName(): array {
    return [
      'New' => 'new',
      'Current' => 'current',
      'Grace' => 'grace',
      'Pending' => 'pending',
      'Expired' => 'lapsed',
      'Cancelled' => 'cancelled',
      'Deceased' => 'deceased',
    ];
  }

  /**
   * Full status classification map (status_id => bucket), built once and cached.
   *
   * Layering: core-name defaults, then is_current_member fallback for anything else,
   * then the statusClassification event (for another ext to override), then the
   * membershipanalytics_status_classification setting (manual override wins).
   */
  public static function getStatusClassification(): array {
    if (isset(\Civi::$statics[__CLASS__]['statusClassification'])) {
      return \Civi::$statics[__CLASS__]['statusClassification'];
    }

    $byName = self::defaultStatusBucketsByName();
    $statuses = \Civi\Api4\MembershipStatus::get(FALSE)
      ->addSelect('id', 'name', 'is_current_member')
      ->execute();

    $map = [];
    foreach ($statuses as $status) {
      if (isset($byName[$status['name']])) {
        $map[$status['id']] = $byName[$status['name']];
      }
      else {
        $map[$status['id']] = !empty($status['is_current_member']) ? 'current' : 'lapsed';
      }
    }

    // Let another extension override the classification for its custom statuses.
    $event = \Civi\Core\Event\GenericHookEvent::create(['classification' => &$map]);
    \Civi::dispatcher()->dispatch('civi.membershipanalytics.statusClassification', $event);

    // Manual setting override (status_id => bucket) wins.
    $override = \Civi::settings()->get('membershipanalytics_status_classification') ?: [];
    foreach ($override as $statusId => $bucket) {
      $map[(int) $statusId] = $bucket;
    }

    \Civi::$statics[__CLASS__]['statusClassification'] = $map;
    return $map;
  }

  /**
   * Resolve the lifecycle bucket for a membership status id.
   */
  public static function statusBucketFor(int $statusId): string {
    return self::getStatusClassification()[$statusId] ?? 'lapsed';
  }

}
