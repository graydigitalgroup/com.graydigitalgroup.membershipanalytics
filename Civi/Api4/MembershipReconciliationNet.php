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
   * The per-year-end state is a boundary CTE walked with LAG/LEAD windows (see aggCte) rather than
   * self-joining the ~tens-of-thousands-of-rows per-membership year-end set, which MySQL 8 runs as a
   * huge nested loop for ~50s. MariaDB keys it either way.
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
    $cte = static::aggCte();
    \CRM_Core_DAO::executeQuery("CREATE OR REPLACE VIEW `$viewName` AS WITH $cte SELECT $select $from");
  }

  protected static function viewSelect(): array {
    return [
      ['select' => 'ROW_NUMBER() OVER (ORDER BY recon.fiscal_year, recon.membership_type_id)', 'name' => 'id', 'data_type' => 'Integer'],
      ['select' => 'recon.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      ['select' => 'recon.year_end_date', 'name' => 'fiscal_year_end', 'data_type' => 'Date', 'title' => ts('Fiscal Year End')],
      ['select' => 'recon.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'recon.opening', 'name' => 'opening', 'data_type' => 'Integer', 'title' => ts('Opening')],
      ['select' => 'recon.entered', 'name' => 'entered', 'data_type' => 'Integer', 'title' => ts('Entered')],
      // Entered split by the status the membership held at the prior year-end (config-driven reasons).
      ['select' => 'recon.entered_new', 'name' => 'entered_new', 'data_type' => 'Integer', 'title' => ts('New')],
      ['select' => 'recon.entered_reactivated', 'name' => 'entered_reactivated', 'data_type' => 'Integer', 'title' => ts('Reactivated')],
      ['select' => 'recon.entered_reinstated', 'name' => 'entered_reinstated', 'data_type' => 'Integer', 'title' => ts('Reinstated')],
      ['select' => 'recon.transfer_in', 'name' => 'transfer_in', 'data_type' => 'Integer', 'title' => ts('Transfers In')],
      ['select' => 'recon.transfer_out', 'name' => 'transfer_out', 'data_type' => 'Integer', 'title' => ts('Transfers Out')],
      ['select' => 'recon.exited', 'name' => 'exited', 'data_type' => 'Integer', 'title' => ts('Exited')],
      // Exited split by the status the membership moved into at this year-end.
      ['select' => 'recon.exited_dropped', 'name' => 'exited_dropped', 'data_type' => 'Integer', 'title' => ts('Dropped')],
      ['select' => 'recon.exited_archived', 'name' => 'exited_archived', 'data_type' => 'Integer', 'title' => ts('Archived')],
      ['select' => 'recon.exited_other', 'name' => 'exited_other', 'data_type' => 'Integer', 'title' => ts('Exited (Other)')],
      ['select' => 'recon.current_count', 'name' => 'current_count', 'data_type' => 'Integer', 'title' => ts('Current')],
      ['select' => '(recon.current_count - recon.opening)', 'name' => 'net_change', 'data_type' => 'Integer', 'title' => ts('Net Change')],
      ['select' => '(recon.opening + recon.entered + recon.transfer_in - recon.exited - recon.transfer_out - recon.current_count)', 'name' => 'reconcile_check', 'data_type' => 'Integer', 'title' => ts('Reconcile Check')],
    ];
  }

  protected static function viewFrom(): string {
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
      FROM year_end ye
      LEFT JOIN year_end op
        ON op.membership_type_id = ye.membership_type_id
       AND op.fiscal_year = ye.fiscal_year - 1
      LEFT JOIN flows_agg fl
        ON fl.fiscal_year = ye.fiscal_year
       AND fl.membership_type_id = ye.membership_type_id
    ) recon";
  }

  /**
   * Chained CTEs, each materialized once by MySQL 8.
   *
   * - last_period: the latest snapshot date in each fiscal year (the year-end instant).
   * - year_end: counted members per category at each fiscal year-end (used for current + opening).
   * - boundary: one row per membership per fiscal year-end it is present at, with its status and —
   *   when counted — its category.
   * - win: boundary walked with LAG (prior year-end) and LEAD (next year-end), so each membership's
   *   counted state and category can be compared across consecutive year-ends without a self-join.
   * - flows: one row per net flow, derived from win (entered / exited / transfer in / transfer out),
   *   carrying a reason from the prior year-end status (entries) or next year-end status (exits).
   * - flows_agg: the flows summed per fiscal_year × category.
   *
   * NULL-safe comparisons (COALESCE on the LAG/LEAD columns) treat "absent at the adjacent year-end"
   * as not-counted, so a first appearance is an entry and a disappearance is an exit.
   */
  private static function aggCte(): string {
    $entryReasons = \CRM_Membershipanalytics_Config::entryReasonStatusIds();
    $exitReasons = \CRM_Membershipanalytics_Config::exitReasonStatusIds();
    $reactivatedIds = self::idList($entryReasons['reactivated']);
    $reinstatedIds = self::idList($entryReasons['reinstated']);
    $droppedIds = self::idList($exitReasons['dropped']);
    $archivedIds = self::idList($exitReasons['archived']);

    return "last_period AS (
        SELECT fiscal_year, MAX(period_end) AS pe
        FROM civicrm_membership_snapshot GROUP BY fiscal_year
      ),
      year_end AS (
        SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted,
               MAX(s.period_end) AS year_end_date
        FROM civicrm_membership_snapshot s
        JOIN last_period ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
        GROUP BY s.fiscal_year, s.membership_type_id
      ),
      boundary AS (
        SELECT s.fiscal_year, s.membership_id, s.status_id,
               s.counts_as_member AS counted,
               CASE WHEN s.counts_as_member = 1 THEN s.membership_type_id END AS counted_type
        FROM civicrm_membership_snapshot s
        JOIN last_period ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
        WHERE s.membership_id IS NOT NULL
      ),
      win AS (
        SELECT fiscal_year, membership_id, counted, counted_type,
               LAG(fiscal_year)  OVER w AS prev_fy,
               LAG(counted)      OVER w AS prev_counted,
               LAG(counted_type) OVER w AS prev_type,
               LAG(status_id)    OVER w AS prev_status,
               LEAD(fiscal_year) OVER w AS next_fy,
               LEAD(counted)     OVER w AS next_counted,
               LEAD(status_id)   OVER w AS next_status
        FROM boundary
        WINDOW w AS (PARTITION BY membership_id ORDER BY fiscal_year)
      ),
      flows AS (
        -- Entered: counted this year-end, not counted at the prior year-end; reason from the status
        -- held at the prior year-end (absent prior year, or any other status, is New).
        SELECT fiscal_year, counted_type AS membership_type_id, 'entry' AS kind,
               CASE WHEN prev_fy = fiscal_year - 1 AND prev_status IN ($reactivatedIds) THEN 'reactivated'
                    WHEN prev_fy = fiscal_year - 1 AND prev_status IN ($reinstatedIds) THEN 'reinstated'
                    ELSE 'new' END AS reason
        FROM win
        WHERE counted = 1
          AND NOT (COALESCE(prev_fy, -1) = fiscal_year - 1 AND COALESCE(prev_counted, 0) = 1)
        UNION ALL
        -- Exited: counted this year-end, not counted next year-end; attributed to next year, from this
        -- year's category; reason from the status it moved into next year-end (absent/other is Other).
        SELECT fiscal_year + 1, counted_type, 'exit',
               CASE WHEN next_fy = fiscal_year + 1 AND next_status IN ($droppedIds) THEN 'dropped'
                    WHEN next_fy = fiscal_year + 1 AND next_status IN ($archivedIds) THEN 'archived'
                    ELSE 'other' END
        FROM win
        WHERE counted = 1
          AND NOT (COALESCE(next_fy, -1) = fiscal_year + 1 AND COALESCE(next_counted, 0) = 1)
        UNION ALL
        -- Transfer out: counted both year-ends, category changed — out of the prior category.
        SELECT fiscal_year, prev_type, 'tout', NULL
        FROM win
        WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type
        UNION ALL
        -- Transfer in: counted both year-ends, category changed — into this year's category.
        SELECT fiscal_year, counted_type, 'tin', NULL
        FROM win
        WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type
      ),
      flows_agg AS (
        SELECT fiscal_year, membership_type_id,
               SUM(kind = 'entry') AS entered,
               SUM(kind = 'entry' AND reason = 'reactivated') AS entered_reactivated,
               SUM(kind = 'entry' AND reason = 'reinstated') AS entered_reinstated,
               SUM(kind = 'entry' AND reason = 'new') AS entered_new,
               SUM(kind = 'exit')  AS exited,
               SUM(kind = 'exit' AND reason = 'dropped') AS exited_dropped,
               SUM(kind = 'exit' AND reason = 'archived') AS exited_archived,
               SUM(kind = 'exit' AND reason = 'other') AS exited_other,
               SUM(kind = 'tin')   AS transfer_in,
               SUM(kind = 'tout')  AS transfer_out
        FROM flows
        GROUP BY fiscal_year, membership_type_id
      )";
  }

  /**
   * A comma-separated id list safe for a SQL IN (…), or '-1' (matches nothing) when empty.
   */
  protected static function idList(array $ids): string {
    $ints = array_map('intval', $ids);
    return $ints ? implode(',', $ints) : '-1';
  }

}
