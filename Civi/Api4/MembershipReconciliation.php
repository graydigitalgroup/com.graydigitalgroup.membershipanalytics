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
      ['select' => 'recon.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'recon.opening', 'name' => 'opening', 'data_type' => 'Integer', 'title' => ts('Opening')],
      ['select' => 'recon.entered', 'name' => 'entered', 'data_type' => 'Integer', 'title' => ts('Entered')],
      ['select' => 'recon.transfer_in', 'name' => 'transfer_in', 'data_type' => 'Integer', 'title' => ts('Transfers In')],
      ['select' => 'recon.transfer_out', 'name' => 'transfer_out', 'data_type' => 'Integer', 'title' => ts('Transfers Out')],
      ['select' => 'recon.exited', 'name' => 'exited', 'data_type' => 'Integer', 'title' => ts('Exited')],
      ['select' => 'recon.current_count', 'name' => 'current_count', 'data_type' => 'Integer', 'title' => ts('Current')],
      ['select' => '(recon.current_count - recon.opening)', 'name' => 'net_change', 'data_type' => 'Integer', 'title' => ts('Net Change')],
      // Should be 0 on every row: opening + entered + transfer_in - exited - transfer_out - current.
      ['select' => '(recon.opening + recon.entered + recon.transfer_in - recon.exited - recon.transfer_out - recon.current_count)', 'name' => 'reconcile_check', 'data_type' => 'Integer', 'title' => ts('Reconcile Check')],
    ];
  }

  protected static function viewFrom(): string {
    // Counting = whatever the snapshot recorded as counts_as_member (reflects the setting).
    $counting = 'SELECT status_id FROM civicrm_membership_snapshot WHERE counts_as_member = 1 GROUP BY status_id';

    // Counted members at each fiscal year-end = the last snapshot in each fiscal_year.
    $yearEnd = "(SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted
                 FROM civicrm_membership_snapshot s
                 JOIN (SELECT fiscal_year, MAX(period_end) AS pe
                       FROM civicrm_membership_snapshot GROUP BY fiscal_year) last_period
                   ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
                 GROUP BY s.fiscal_year, s.membership_type_id)";

    // Flows per fiscal_year × category. Each event is exploded into the category bucket(s) it
    // affects, then pivoted. A transfer touches two categories (out of from_type, into to_type).
    $flows = "(SELECT fiscal_year, membership_type_id,
                      SUM(kind = 'entry') AS entered,
                      SUM(kind = 'exit')  AS exited,
                      SUM(kind = 'tin')   AS transfer_in,
                      SUM(kind = 'tout')  AS transfer_out
               FROM (
                 -- Non-transfer entry: crosses into counting, attributed to the event's type.
                 SELECT fiscal_year, membership_type_id, 'entry' AS kind
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NULL
                   AND (from_status_id IS NULL OR from_status_id NOT IN ($counting))
                   AND to_status_id IN ($counting)
                 UNION ALL
                 -- Non-transfer exit: crosses out of counting, attributed to the event's type.
                 SELECT fiscal_year, membership_type_id, 'exit'
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NULL
                   AND from_status_id IN ($counting)
                   AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
                 UNION ALL
                 -- Transfer that stays counted: out of the old type.
                 SELECT fiscal_year, from_type_id, 'tout'
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting) AND to_status_id IN ($counting)
                 UNION ALL
                 -- Transfer that stays counted: into the new type.
                 SELECT fiscal_year, to_type_id, 'tin'
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting) AND to_status_id IN ($counting)
                 UNION ALL
                 -- Transfer that also leaves counting: exit from the old type.
                 SELECT fiscal_year, from_type_id, 'exit'
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND from_status_id IN ($counting)
                   AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
                 UNION ALL
                 -- Transfer that also joins counting: entry to the new type.
                 SELECT fiscal_year, to_type_id, 'entry'
                 FROM civicrm_membership_event
                 WHERE from_type_id IS NOT NULL
                   AND (from_status_id IS NULL OR from_status_id NOT IN ($counting))
                   AND to_status_id IN ($counting)
               ) exploded
               GROUP BY fiscal_year, membership_type_id)";

    return "FROM (
      SELECT ye.fiscal_year, ye.membership_type_id,
             ye.counted AS current_count,
             COALESCE(op.counted, 0) AS opening,
             COALESCE(fl.entered, 0) AS entered,
             COALESCE(fl.transfer_in, 0) AS transfer_in,
             COALESCE(fl.transfer_out, 0) AS transfer_out,
             COALESCE(fl.exited, 0) AS exited
      FROM $yearEnd ye
      LEFT JOIN $yearEnd op
        ON op.membership_type_id = ye.membership_type_id
       AND op.fiscal_year = ye.fiscal_year - 1
      LEFT JOIN $flows fl
        ON fl.fiscal_year = ye.fiscal_year
       AND fl.membership_type_id = ye.membership_type_id
    ) recon";
  }

}
