<?php

namespace Civi\Api4;

/**
 * Membership Reconciliation (SQL View) — the opening/flows/current identity per category.
 *
 * One row per fiscal_year × membership_type_id, carrying the reconciliation identity
 *
 *   opening + entered + transfer_in − exited − transfer_out = current
 *
 * where opening is the counted members at the prior fiscal year-end, current is the
 * counted members at this fiscal year-end, and the flows are counted from MembershipEvent
 * over the fiscal year. The identity is the built-in validation: `reconcile_check` is 0 on
 * every row when the event log and the snapshot agree.
 *
 * Client-agnostic. "Counting" is whatever the snapshot recorded as counts_as_member (which
 * is driven by the counting-status setting), read back here rather than hardcoded, so a
 * change to the counting configuration flows through on the next snapshot rebuild. The
 * fiscal year-end is the latest snapshot within each fiscal_year (not a hardcoded month),
 * so this works for any fiscalYearStart. Filtering to reportable types and presenting the
 * numbers is the report layer's job (a SavedSearch/SearchDisplay over this view).
 *
 * Flow attribution (validated against a per-membership decomposition):
 *  - A status change that crosses the counting boundary is an entry/exit attributed to the
 *    membership's type as of the event (membership_type_id).
 *  - A type change (transfer) that stays counted is a transfer_out of the old type and a
 *    transfer_in of the new type.
 *  - A type change that also crosses the boundary is an exit from the old type (if it leaves
 *    counting) or an entry to the new type (if it joins counting).
 *
 * @searchable secondary
 * @package Civi\Api4
 */
class MembershipReconciliation extends Generic\SqlView {

  protected static function getEntityTitle(bool $plural = FALSE): string {
    return $plural ? ts('Membership Reconciliations') : ts('Membership Reconciliation');
  }

  /**
   * Allow read for any CiviCRM user; this view holds only aggregate counts by category and
   * fiscal year — no contact-level data. Mirrors MembershipTrend.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

  /**
   * Rebuild the view atomically on every Api4 entityTypes cache rebuild. The base handler
   * does DROP VIEW then CREATE VIEW, which races under concurrent cache rebuilds; a single
   * CREATE OR REPLACE avoids the window. Mirrors MembershipTrend.
   *
   * @internal
   */
  public static function _on_civi_api4_entityTypes(\Civi\Core\Event\GenericHookEvent $event): void {
    $viewName = static::viewName();
    $selects = [];
    foreach (static::viewSelect() as $field) {
      $selects[] = "{$field['select']} AS `{$field['name']}`";
    }
    $select = implode(', ', $selects);
    $from = static::viewFrom();
    \CRM_Core_DAO::executeQuery("CREATE OR REPLACE VIEW `$viewName` AS SELECT $select $from");
  }

  protected static function viewSelect(): array {
    return [
      // Read-only, re-queried each time, so a ROW_NUMBER over a stable ordering is enough.
      ['select' => 'ROW_NUMBER() OVER (ORDER BY recon.fiscal_year, recon.membership_type_id)', 'name' => 'id', 'data_type' => 'Integer'],
      // Reuse the snapshot's field metadata (type pseudoconstant/FK) for these columns.
      ['select' => 'recon.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      // The fiscal year-end date, so a report can filter by fiscal year with SearchKit's
      // self-maintaining relative date options ("This fiscal year", etc.).
      ['select' => 'recon.year_end_date', 'name' => 'fiscal_year_end', 'data_type' => 'Date', 'title' => ts('Fiscal Year End')],
      ['select' => 'recon.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'recon.opening', 'name' => 'opening', 'data_type' => 'Integer', 'title' => ts('Opening')],
      ['select' => 'recon.entered', 'name' => 'entered', 'data_type' => 'Integer', 'title' => ts('Entered')],
      // Entered split by the status the membership came from (config-driven reason mapping).
      ['select' => 'recon.entered_new', 'name' => 'entered_new', 'data_type' => 'Integer', 'title' => ts('New')],
      ['select' => 'recon.entered_reactivated', 'name' => 'entered_reactivated', 'data_type' => 'Integer', 'title' => ts('Reactivated')],
      ['select' => 'recon.entered_reinstated', 'name' => 'entered_reinstated', 'data_type' => 'Integer', 'title' => ts('Reinstated')],
      ['select' => 'recon.transfer_in', 'name' => 'transfer_in', 'data_type' => 'Integer', 'title' => ts('Transfers In')],
      ['select' => 'recon.transfer_out', 'name' => 'transfer_out', 'data_type' => 'Integer', 'title' => ts('Transfers Out')],
      ['select' => 'recon.exited', 'name' => 'exited', 'data_type' => 'Integer', 'title' => ts('Exited')],
      // Exited split by the status the membership moved into.
      ['select' => 'recon.exited_dropped', 'name' => 'exited_dropped', 'data_type' => 'Integer', 'title' => ts('Dropped')],
      ['select' => 'recon.exited_archived', 'name' => 'exited_archived', 'data_type' => 'Integer', 'title' => ts('Archived')],
      ['select' => 'recon.exited_other', 'name' => 'exited_other', 'data_type' => 'Integer', 'title' => ts('Exited (Other)')],
      ['select' => 'recon.current_count', 'name' => 'current_count', 'data_type' => 'Integer', 'title' => ts('Current')],
      ['select' => '(recon.current_count - recon.opening)', 'name' => 'net_change', 'data_type' => 'Integer', 'title' => ts('Net Change')],
      // Should be 0 on every row: opening + entered + transfer_in - exited - transfer_out - current.
      ['select' => '(recon.opening + recon.entered + recon.transfer_in - recon.exited - recon.transfer_out - recon.current_count)', 'name' => 'reconcile_check', 'data_type' => 'Integer', 'title' => ts('Reconcile Check')],
    ];
  }

  protected static function viewFrom(): string {
    // Counting = whatever the snapshot recorded as counts_as_member (reflects the setting).
    $counting = 'SELECT status_id FROM civicrm_membership_snapshot WHERE counts_as_member = 1 GROUP BY status_id';

    // Config-driven reason mapping: which prior statuses make an entry a reactivation vs a
    // reinstatement, and which destination statuses make an exit a drop vs an archive.
    $entryReasons = \CRM_Membershipanalytics_Config::entryReasonStatusIds();
    $exitReasons = \CRM_Membershipanalytics_Config::exitReasonStatusIds();
    $reactivatedIds = self::idList($entryReasons['reactivated']);
    $reinstatedIds = self::idList($entryReasons['reinstated']);
    $droppedIds = self::idList($exitReasons['dropped']);
    $archivedIds = self::idList($exitReasons['archived']);

    // Counted members at each fiscal year-end = the last snapshot in each fiscal_year.
    $yearEnd = "(SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted,
                        MAX(s.period_end) AS year_end_date
                 FROM civicrm_membership_snapshot s
                 JOIN (SELECT fiscal_year, MAX(period_end) AS pe
                       FROM civicrm_membership_snapshot GROUP BY fiscal_year) last_period
                   ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
                 GROUP BY s.fiscal_year, s.membership_type_id)";

    // Flows per fiscal_year × category. Each event is exploded into the category bucket(s) it
    // affects, then pivoted. A transfer touches two categories (out of from_type, into to_type).
    // class_status carries the status that classifies the reason: the from_status for an entry
    // (where it came from) and the to_status for an exit (where it went); NULL for transfers.
    $flows = "(SELECT fiscal_year, membership_type_id,
                      SUM(kind = 'entry') AS entered,
                      SUM(kind = 'entry' AND class_status IN ($reactivatedIds)) AS entered_reactivated,
                      SUM(kind = 'entry' AND class_status IN ($reinstatedIds)) AS entered_reinstated,
                      SUM(kind = 'entry' AND (class_status IS NULL
                            OR (class_status NOT IN ($reactivatedIds) AND class_status NOT IN ($reinstatedIds)))) AS entered_new,
                      SUM(kind = 'exit')  AS exited,
                      SUM(kind = 'exit' AND class_status IN ($droppedIds)) AS exited_dropped,
                      SUM(kind = 'exit' AND class_status IN ($archivedIds)) AS exited_archived,
                      SUM(kind = 'exit' AND (class_status IS NULL
                            OR (class_status NOT IN ($droppedIds) AND class_status NOT IN ($archivedIds)))) AS exited_other,
                      SUM(kind = 'tin')   AS transfer_in,
                      SUM(kind = 'tout')  AS transfer_out
               FROM (
                 -- Non-transfer entry: crosses into counting, attributed to the event's type.
                 SELECT fiscal_year, membership_type_id, 'entry' AS kind, from_status_id AS class_status
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NULL
                   AND (from_status_id IS NULL OR from_status_id NOT IN ($counting))
                   AND to_status_id IN ($counting)
                 UNION ALL
                 -- Non-transfer exit: crosses out of counting, attributed to the event's type.
                 SELECT fiscal_year, membership_type_id, 'exit', to_status_id
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NULL
                   AND from_status_id IN ($counting)
                   AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
                 UNION ALL
                 -- Transfer that stays counted: out of the old type.
                 SELECT fiscal_year, from_type_id, 'tout', NULL
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting) AND to_status_id IN ($counting)
                 UNION ALL
                 -- Transfer that stays counted: into the new type.
                 SELECT fiscal_year, to_type_id, 'tin', NULL
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting) AND to_status_id IN ($counting)
                 UNION ALL
                 -- Transfer that also leaves counting: exit from the old type.
                 SELECT fiscal_year, from_type_id, 'exit', to_status_id
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting)
                   AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
                 UNION ALL
                 -- Transfer that also joins counting: entry to the new type.
                 SELECT fiscal_year, to_type_id, 'entry', from_status_id
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND (from_status_id IS NULL OR from_status_id NOT IN ($counting))
                   AND to_status_id IN ($counting)
               ) exploded
               GROUP BY fiscal_year, membership_type_id)";

    return "FROM (
      SELECT ye.fiscal_year, ye.membership_type_id,
             ye.year_end_date,
             ye.counted AS current_count,
             COALESCE(op.counted, 0) AS opening,
             COALESCE(fl.entered, 0) AS entered,
             COALESCE(fl.entered_new, 0) AS entered_new,
             COALESCE(fl.entered_reactivated, 0) AS entered_reactivated,
             COALESCE(fl.entered_reinstated, 0) AS entered_reinstated,
             COALESCE(fl.transfer_in, 0) AS transfer_in,
             COALESCE(fl.transfer_out, 0) AS transfer_out,
             COALESCE(fl.exited, 0) AS exited,
             COALESCE(fl.exited_dropped, 0) AS exited_dropped,
             COALESCE(fl.exited_archived, 0) AS exited_archived,
             COALESCE(fl.exited_other, 0) AS exited_other
      FROM $yearEnd ye
      LEFT JOIN $yearEnd op
        ON op.membership_type_id = ye.membership_type_id
       AND op.fiscal_year = ye.fiscal_year - 1
      LEFT JOIN $flows fl
        ON fl.fiscal_year = ye.fiscal_year
       AND fl.membership_type_id = ye.membership_type_id
    ) recon";
  }

  /**
   * A comma-separated id list safe for a SQL IN (…), or '-1' (matches nothing) when empty.
   */
  protected static function idList(array $ids): string {
    $ints = array_map('intval', $ids);
    return $ints ? implode(',', $ints) : '-1';
  }

}
