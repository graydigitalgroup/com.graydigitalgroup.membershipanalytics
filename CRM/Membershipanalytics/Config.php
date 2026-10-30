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
   * The active set is UI-managed: the membershipanalytics_dimensions option group holds one value
   * per active dimension (an admin adds/removes them on the Membership Dimensions screen, no code).
   * When that option group has no active values, it falls back to the setting, then to the core
   * demographic defaults. The historizedDimensions event can still add dimensions programmatically.
   */
  public static function historizedDimensions(): array {
    $fromGroup = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'membershipanalytics_dimensions')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('weight', 'ASC')
      ->execute()
      ->column('value');

    if ($fromGroup) {
      $dimensions = $fromGroup;
    }
    else {
      $configured = \Civi::settings()->get('membershipanalytics_historized_dimensions');
      $dimensions = !empty($configured) ? $configured : ['gender', 'country', 'state', 'city'];
    }

    $event = \Civi\Core\Event\GenericHookEvent::create(['dimensions' => &$dimensions]);
    \Civi::dispatcher()->dispatch('civi.membershipanalytics.historizedDimensions', $event);

    return array_values(array_unique($dimensions));
  }

  /**
   * Where each historized dimension lives on the snapshot and how to stamp its value.
   *
   * Maps a dimension field key (as returned by historizedDimensions()) to its snapshot
   * column, that column's SQL type, the JOIN that exposes the source row as alias `src`, and
   * the SQL expression that reads the value. This is the built-in map for the core demographic
   * dimensions. A site adds its own — any contact-related table, including a custom-field table
   * — by listening to the dimensionColumnMap event; that is how non-core dimensions such as
   * CNS's is_fellow plug into the same column-sync and stamping engine without this extension
   * knowing anything about them. The join is raw SQL against the snapshot alias `s`, so it is
   * defined in code (an event listener), never from user input.
   *
   * @return array<string, array{column: string, sql_type: string, join: string, expr: string}>
   */
  public static function dimensionColumnMap(): array {
    $contactJoin = 'JOIN civicrm_contact src ON src.id = s.contact_id';
    // Primary address; LEFT so a contact without one stamps NULL rather than dropping the row.
    $addressJoin = 'LEFT JOIN civicrm_address src ON src.contact_id = s.contact_id AND src.is_primary = 1';
    $map = [
      'gender' => ['column' => 'gender_id', 'sql_type' => 'int unsigned', 'join' => $contactJoin, 'expr' => 'src.gender_id'],
      'country' => ['column' => 'country_id', 'sql_type' => 'int unsigned', 'join' => $addressJoin, 'expr' => 'src.country_id'],
      'state' => ['column' => 'state_province_id', 'sql_type' => 'int unsigned', 'join' => $addressJoin, 'expr' => 'src.state_province_id'],
      'city' => ['column' => 'city', 'sql_type' => 'varchar(64)', 'join' => $addressJoin, 'expr' => 'src.city'],
    ];

    $event = \Civi\Core\Event\GenericHookEvent::create(['map' => &$map]);
    \Civi::dispatcher()->dispatch('civi.membershipanalytics.dimensionColumnMap', $event);

    return $map;
  }

  /**
   * The dimensions that are both active (historized) and have a column mapping, as
   * key => spec. This is the working set the column-sync and stamping engine operate on.
   *
   * @return array<string, array{column: string, sql_type: string, join: string, expr: string}>
   */
  public static function activeDimensions(): array {
    $map = self::dimensionColumnMap();
    $active = [];
    foreach (self::historizedDimensions() as $dim) {
      if (isset($map[$dim])) {
        // A built-in or event-provided dimension (e.g. gender, geography, or a coded override).
        $active[$dim] = $map[$dim];
      }
      else {
        // Otherwise resolve it generically from field metadata, so a dimension added in the UI
        // (a custom field or contact field) is stamped with no code.
        $resolved = self::resolveDimension($dim);
        if ($resolved) {
          $active[$dim] = $resolved;
        }
      }
    }
    return $active;
  }

  /**
   * Resolve a dimension field key to {column, sql_type, join, expr} from CiviCRM field metadata,
   * so any contact custom field (or contact base column) can be a snapshot dimension without a
   * hand-written mapping. Returns NULL for a key that cannot be stamped onto a per-contact column
   * (e.g. a custom field on a non-contact entity).
   *
   * Accepted keys: a custom field as "custom_<id>" or "<CustomGroupName>.<FieldName>", or a plain
   * civicrm_contact column name.
   *
   * @return array{column: string, sql_type: string, join: string, expr: string}|null
   */
  public static function resolveDimension(string $key): ?array {
    $cf = NULL;
    if (preg_match('/^custom_(\d+)$/', $key, $m)) {
      $cf = self::customFieldMeta((int) $m[1], NULL, NULL);
    }
    elseif (strpos($key, '.') !== FALSE) {
      [$group, $field] = explode('.', $key, 2);
      $cf = self::customFieldMeta(NULL, $group, $field);
    }

    if ($cf) {
      // Must extend a contact type so it joins on entity_id = contact_id.
      if (!in_array($cf['extends'], ['Contact', 'Individual', 'Organization', 'Household'], TRUE)) {
        return NULL;
      }
      return [
        'column' => $cf['column_name'],
        'sql_type' => self::sqlTypeForDataType($cf['data_type']),
        'join' => 'LEFT JOIN `' . $cf['table_name'] . '` src ON src.entity_id = s.contact_id',
        'expr' => 'src.`' . $cf['column_name'] . '`',
      ];
    }

    // A plain civicrm_contact column (validated against information_schema to stay injection-safe).
    if (preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
      $type = \CRM_Core_DAO::singleValueQuery(
        "SELECT column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'civicrm_contact' AND column_name = %1",
        [1 => [$key, 'String']]
      );
      if ($type) {
        return [
          'column' => $key,
          'sql_type' => $type,
          'join' => 'JOIN civicrm_contact src ON src.id = s.contact_id',
          'expr' => 'src.`' . $key . '`',
        ];
      }
    }
    return NULL;
  }

  /**
   * Custom field metadata (column_name, data_type, group table_name + extends), by id or by
   * group name + field name. Returns NULL if not found.
   */
  private static function customFieldMeta(?int $id, ?string $groupName, ?string $fieldName): ?array {
    $query = \Civi\Api4\CustomField::get(FALSE)
      ->addSelect('column_name', 'data_type', 'custom_group_id.table_name', 'custom_group_id.extends')
      ->setLimit(1);
    if ($id) {
      $query->addWhere('id', '=', $id);
    }
    else {
      $query->addWhere('custom_group_id.name', '=', $groupName)->addWhere('name', '=', $fieldName);
    }
    $cf = $query->execute()->first();
    if (!$cf) {
      return NULL;
    }
    return [
      'column_name' => $cf['column_name'],
      'data_type' => $cf['data_type'],
      'table_name' => $cf['custom_group_id.table_name'],
      'extends' => $cf['custom_group_id.extends'],
    ];
  }

  /**
   * SQL column type for a CiviCRM custom-field data type.
   */
  private static function sqlTypeForDataType(string $dataType): string {
    switch ($dataType) {
      case 'Int':
      case 'Boolean':
      case 'StateProvince':
      case 'Country':
      case 'ContactReference':
        return 'int';

      case 'Float':
      case 'Money':
        return 'decimal(20,2)';

      case 'Date':
        return 'datetime';

      default:
        return 'varchar(255)';
    }
  }

  /**
   * APIv4 field definitions for the snapshot's dynamically added dimension columns.
   *
   * The entity's static getFields() lists the fixed columns; this reads the live table and returns
   * a field definition for every other column (the dimensions added by MembershipSnapshot.syncColumns,
   * e.g. is_fellow or anything configured in the UI dimension manager), so SearchKit and APIv4 can
   * select, group and filter on them. Raw SQL on information_schema, not APIv4, because this runs
   * while entity metadata is being built.
   *
   * @param array $excludeColumns Column names already defined as static fields, to skip.
   * @return array<string, array> name => field definition
   */
  public static function snapshotDimensionFields(array $excludeColumns): array {
    $exclude = array_map('strtolower', $excludeColumns);
    $fields = [];
    // Alias each column explicitly: on MySQL 8 information_schema result columns come back with
    // uppercase labels (DATA_TYPE), so unaliased $dao->data_type would be null; an explicit lowercase
    // alias is returned verbatim on both MySQL 8 and MariaDB/MySQL 5.7.
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT column_name AS column_name, data_type AS data_type, column_type AS column_type
       FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'civicrm_membership_snapshot'
       ORDER BY ordinal_position"
    );
    while ($dao->fetch()) {
      if (in_array(strtolower($dao->column_name), $exclude, TRUE)) {
        continue;
      }
      $fields[$dao->column_name] = [
        'title' => ucwords(str_replace('_', ' ', $dao->column_name)),
        'sql_type' => $dao->column_type,
        'input_type' => self::inputTypeForColumnType($dao->data_type),
        'description' => 'Stamped membership-snapshot dimension.',
      ];
    }
    return $fields;
  }

  /**
   * APIv4 input type for a MySQL column data type (information_schema data_type).
   */
  private static function inputTypeForColumnType(?string $dataType): string {
    switch (strtolower((string) $dataType)) {
      case 'date':
      case 'datetime':
      case 'timestamp':
        return 'Select Date';

      case 'tinyint':
      case 'smallint':
      case 'mediumint':
      case 'int':
      case 'bigint':
      case 'decimal':
      case 'float':
      case 'double':
        return 'Number';

      default:
        return 'Text';
    }
  }

  /**
   * Movement reason for a membership's prior status (on entry) and next status (on exit).
   *
   * entry: the status a membership held BEFORE it crossed into counting → 'new' | 'reactivated'
   * | 'reinstated'. exit: the status it moved INTO when it left counting → 'dropped' | 'archived'
   * | 'other'. Defaults derive from the lifecycle bucket (dropped → reactivated/dropped, archived
   * → reinstated/archived, everything else → new/other). The reason is deliberately separate from
   * the bucket, because two lapsed statuses can re-enter for different reasons: a site refines
   * individual statuses by listening to the movementReason event — e.g. CNS maps In-Arrears to
   * reactivated and Expired to reinstated without changing their lifecycle bucket.
   *
   * @return array{entry: array<int,string>, exit: array<int,string>}
   */
  public static function movementReasonByStatus(): array {
    $classification = self::getStatusClassification();
    $entry = [];
    $exit = [];
    foreach ($classification as $sid => $bucket) {
      $entry[(int) $sid] = $bucket === 'dropped' ? 'reactivated' : ($bucket === 'archived' ? 'reinstated' : 'new');
      $exit[(int) $sid] = in_array($bucket, ['dropped', 'archived'], TRUE) ? $bucket : 'other';
    }

    $event = \Civi\Core\Event\GenericHookEvent::create(['entry' => &$entry, 'exit' => &$exit]);
    \Civi::dispatcher()->dispatch('civi.membershipanalytics.movementReason', $event);

    return ['entry' => $entry, 'exit' => $exit];
  }

  /**
   * Entry-reason status ids as reason => [status_id,...]. A membership entering from a status not
   * listed here is counted as 'new'.
   */
  public static function entryReasonStatusIds(): array {
    $out = ['reactivated' => [], 'reinstated' => []];
    foreach (self::movementReasonByStatus()['entry'] as $sid => $reason) {
      if (isset($out[$reason])) {
        $out[$reason][] = (int) $sid;
      }
    }
    return $out;
  }

  /**
   * Exit-reason status ids as reason => [status_id,...]. A membership exiting into a status not
   * listed here is counted as 'other'.
   */
  public static function exitReasonStatusIds(): array {
    $out = ['dropped' => [], 'archived' => []];
    foreach (self::movementReasonByStatus()['exit'] as $sid => $reason) {
      if (isset($out[$reason])) {
        $out[$reason][] = (int) $sid;
      }
    }
    return $out;
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
    // Raw SQL, not APIv4: this is also called while SQL views are being (re)built during the
    // APIv4 entityTypes rebuild, and an APIv4 call there would re-enter that rebuild and recurse.
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT id, name, is_current_member FROM civicrm_membership_status'
    );

    $map = [];
    while ($dao->fetch()) {
      if (isset($byName[$dao->name])) {
        $map[(int) $dao->id] = $byName[$dao->name];
      }
      else {
        $map[(int) $dao->id] = !empty($dao->is_current_member) ? 'current' : 'lapsed';
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
