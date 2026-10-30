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
 * counted members at this fiscal year-end, and the flows are counted on the GROSS / movement
 * basis (every counting boundary crossing during the fiscal year). The identity is the built-in
 * validation: `reconcile_check` is 0 on every row when the flows and the snapshot agree.
 *
 * The per-movement classification (the expensive part) is NOT done in this view. It is materialized
 * once per refresh into civicrm_membership_year_end_flow (basis 'gross'); see MembershipYearEndFlow
 * and its Rebuild action. This view aggregates that small fact by category and fiscal year and joins
 * the counted population for opening/current, so it is a cheap indexed read rather than six scans of
 * the event log on every page view. MembershipReconciliationNet is the same shape on the net basis.
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
   * Rebuild the view atomically on every Api4 entityTypes cache rebuild. It reads the materialized
   * fact; if that table does not exist yet (a cache flush before the schema upgrade on a fresh
   * deploy), leave the view as-is rather than failing on a missing table.
   *
   * @internal
   */
  public static function _on_civi_api4_entityTypes(\Civi\Core\Event\GenericHookEvent $event): void {
    // The view filters on basis, so require the fact table AND that column before rebuilding; a flush
    // that runs before the schema upgrade otherwise fails on an unknown column. Short-circuits so
    // SHOW COLUMNS is not run against a missing table.
    if (!\CRM_Core_DAO::singleValueQuery("SHOW TABLES LIKE 'civicrm_membership_year_end_flow'")
        || !\CRM_Core_DAO::singleValueQuery("SHOW COLUMNS FROM civicrm_membership_year_end_flow LIKE 'basis'")) {
      return;
    }
    $viewName = static::viewName();
    $selects = [];
    foreach (static::viewSelect() as $field) {
      $selects[] = "{$field['select']} AS `{$field['name']}`";
    }
    $select = implode(', ', $selects);
    $from = static::viewFrom();
    $cte = static::aggCte();
    \CRM_Core_DAO::executeQuery("CREATE OR REPLACE VIEW `$viewName` AS WITH $cte SELECT $select $from");
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
    return "FROM (
      SELECT ye.fiscal_year, ye.membership_type_id, ye.year_end_date,
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
      FROM year_end ye
      LEFT JOIN year_end op
        ON op.membership_type_id = ye.membership_type_id AND op.fiscal_year = ye.fiscal_year - 1
      LEFT JOIN flows_agg fl
        ON fl.fiscal_year = ye.fiscal_year AND fl.membership_type_id = ye.membership_type_id
    ) recon";
  }

  /**
   * Counted population per category per fiscal year-end (for opening/current), and the gross flows
   * aggregated from the materialized per-membership fact (for entered/exited/transfers + reasons).
   */
  private static function aggCte(): string {
    return "last_period AS (
        SELECT fiscal_year, MAX(period_end) AS pe FROM civicrm_membership_snapshot GROUP BY fiscal_year
      ),
      year_end AS (
        SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted,
               MAX(s.period_end) AS year_end_date
        FROM civicrm_membership_snapshot s
        JOIN last_period ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
        GROUP BY s.fiscal_year, s.membership_type_id
      ),
      flows_agg AS (
        SELECT fiscal_year, membership_type_id,
               SUM(flow = 'entered') AS entered,
               SUM(flow = 'entered' AND reason = 'new') AS entered_new,
               SUM(flow = 'entered' AND reason = 'reactivated') AS entered_reactivated,
               SUM(flow = 'entered' AND reason = 'reinstated') AS entered_reinstated,
               SUM(flow = 'exited') AS exited,
               SUM(flow = 'exited' AND reason = 'dropped') AS exited_dropped,
               SUM(flow = 'exited' AND reason = 'archived') AS exited_archived,
               SUM(flow = 'exited' AND reason = 'other') AS exited_other,
               SUM(flow = 'transfer_in') AS transfer_in,
               SUM(flow = 'transfer_out') AS transfer_out
        FROM civicrm_membership_year_end_flow
        WHERE basis = 'gross'
        GROUP BY fiscal_year, membership_type_id
      )";
  }

}
