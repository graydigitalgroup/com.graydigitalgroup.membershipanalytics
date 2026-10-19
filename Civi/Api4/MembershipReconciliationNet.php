<?php

namespace Civi\Api4;

/**
 * Membership Reconciliation — Net / boundary basis (SQL View).
 *
 * Same identity and columns as MembershipReconciliation
 *
 *   opening + entered + transfer_in − exited − transfer_out = current
 *
 * but the flows are counted on a NET / boundary basis: each membership's counted state at the
 * prior fiscal year-end is compared with its state at this fiscal year-end, and only that net
 * difference is a flow. A membership that left and rejoined counting within the same year is
 * unchanged at both year-ends, so it contributes nothing here — where the event-based
 * MembershipReconciliation (the "gross" / movement basis) would record both the exit and the
 * re-entry. The two views therefore share opening, current and net_change but differ in the size
 * of entered/exited/transfers; offering both lets a report show churn (gross) or net position.
 *
 * Boundary attribution per membership, for fiscal year Y:
 *  - counted at Y but not at Y−1  → entered (into the Y type)
 *  - counted at Y−1 but not at Y  → exited (from the Y−1 type), attributed to year Y
 *  - counted at both, type unchanged → continued (no flow)
 *  - counted at both, type changed → transfer_out of the Y−1 type, transfer_in to the Y type
 *
 * Client-agnostic: counting is whatever the snapshot recorded as counts_as_member, and the
 * fiscal year-end is the latest snapshot in each fiscal_year. Filtering to reportable types and
 * presentation are the report layer's job.
 *
 * @searchable secondary
 * @package Civi\Api4
 */
class MembershipReconciliationNet extends Generic\SqlView {

  protected static function getEntityTitle(bool $plural = FALSE): string {
    return $plural ? ts('Membership Reconciliations (Net)') : ts('Membership Reconciliation (Net)');
  }

  /**
   * Aggregate counts by category and fiscal year only — no contact-level data.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

  /**
   * Rebuild the view atomically on cache rebuild (single CREATE OR REPLACE, mirrors the siblings).
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
      ['select' => 'ROW_NUMBER() OVER (ORDER BY recon.fiscal_year, recon.membership_type_id)', 'name' => 'id', 'data_type' => 'Integer'],
      ['select' => 'recon.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      ['select' => 'recon.year_end_date', 'name' => 'fiscal_year_end', 'data_type' => 'Date', 'title' => ts('Fiscal Year End')],
      ['select' => 'recon.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'recon.opening', 'name' => 'opening', 'data_type' => 'Integer', 'title' => ts('Opening')],
      ['select' => 'recon.entered', 'name' => 'entered', 'data_type' => 'Integer', 'title' => ts('Entered')],
      ['select' => 'recon.transfer_in', 'name' => 'transfer_in', 'data_type' => 'Integer', 'title' => ts('Transfers In')],
      ['select' => 'recon.transfer_out', 'name' => 'transfer_out', 'data_type' => 'Integer', 'title' => ts('Transfers Out')],
      ['select' => 'recon.exited', 'name' => 'exited', 'data_type' => 'Integer', 'title' => ts('Exited')],
      ['select' => 'recon.current_count', 'name' => 'current_count', 'data_type' => 'Integer', 'title' => ts('Current')],
      ['select' => '(recon.current_count - recon.opening)', 'name' => 'net_change', 'data_type' => 'Integer', 'title' => ts('Net Change')],
      ['select' => '(recon.opening + recon.entered + recon.transfer_in - recon.exited - recon.transfer_out - recon.current_count)', 'name' => 'reconcile_check', 'data_type' => 'Integer', 'title' => ts('Reconcile Check')],
    ];
  }

  protected static function viewFrom(): string {
    // Counted members at each fiscal year-end = the last snapshot in each fiscal_year.
    $yearEnd = "(SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted,
                        MAX(s.period_end) AS year_end_date
                 FROM civicrm_membership_snapshot s
                 JOIN (SELECT fiscal_year, MAX(period_end) AS pe
                       FROM civicrm_membership_snapshot GROUP BY fiscal_year) last_period
                   ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
                 GROUP BY s.fiscal_year, s.membership_type_id)";

    // One counted row per membership per fiscal year-end (the boundary state to compare).
    $countedYe = "(SELECT s.fiscal_year, s.membership_id, s.membership_type_id
                   FROM civicrm_membership_snapshot s
                   JOIN (SELECT fiscal_year, MAX(period_end) AS pe
                         FROM civicrm_membership_snapshot GROUP BY fiscal_year) last_period
                     ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
                   WHERE s.counts_as_member = 1 AND s.membership_id IS NOT NULL)";

    // Net flows: compare each membership's counted state across consecutive year-ends.
    $flows = "(SELECT fiscal_year, membership_type_id,
                      SUM(kind = 'entry') AS entered,
                      SUM(kind = 'exit')  AS exited,
                      SUM(kind = 'tin')   AS transfer_in,
                      SUM(kind = 'tout')  AS transfer_out
               FROM (
                 -- Entered: counted this year-end, not counted at the prior year-end.
                 SELECT cur.fiscal_year, cur.membership_type_id, 'entry' AS kind
                 FROM $countedYe cur
                 LEFT JOIN $countedYe prev
                   ON prev.membership_id = cur.membership_id AND prev.fiscal_year = cur.fiscal_year - 1
                 WHERE prev.membership_id IS NULL
                 UNION ALL
                 -- Exited: counted at the prior year-end, not counted this year-end (attributed to this year).
                 SELECT prev.fiscal_year + 1, prev.membership_type_id, 'exit'
                 FROM $countedYe prev
                 LEFT JOIN $countedYe cur
                   ON cur.membership_id = prev.membership_id AND cur.fiscal_year = prev.fiscal_year + 1
                 WHERE cur.membership_id IS NULL
                 UNION ALL
                 -- Transfer out: counted both year-ends, type changed — out of the prior type.
                 SELECT cur.fiscal_year, prev.membership_type_id, 'tout'
                 FROM $countedYe cur
                 JOIN $countedYe prev
                   ON prev.membership_id = cur.membership_id AND prev.fiscal_year = cur.fiscal_year - 1
                 WHERE cur.membership_type_id <> prev.membership_type_id
                 UNION ALL
                 -- Transfer in: counted both year-ends, type changed — into the new type.
                 SELECT cur.fiscal_year, cur.membership_type_id, 'tin'
                 FROM $countedYe cur
                 JOIN $countedYe prev
                   ON prev.membership_id = cur.membership_id AND prev.fiscal_year = cur.fiscal_year - 1
                 WHERE cur.membership_type_id <> prev.membership_type_id
               ) exploded
               GROUP BY fiscal_year, membership_type_id)";

    return "FROM (
      SELECT ye.fiscal_year, ye.membership_type_id,
             ye.year_end_date,
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
