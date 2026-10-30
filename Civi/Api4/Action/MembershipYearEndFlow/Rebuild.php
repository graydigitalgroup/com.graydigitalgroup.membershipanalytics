<?php

namespace Civi\Api4\Action\MembershipYearEndFlow;

use CRM_Membershipanalytics_Config as Config;

/**
 * Rebuild the materialized reconciliation fact from the snapshot and the event log.
 *
 * Writes one row per net flow per membership for both reconciliation bases:
 *  - basis 'net': each membership's counted state compared across consecutive fiscal year-ends
 *    (one sorted window pass over the snapshot), so same-year churn nets out.
 *  - basis 'gross': every counting boundary crossing during the year (from the event log), so
 *    churn is counted.
 *
 * Doing the expensive classification once here lets the reconciliation views be a cheap indexed
 * GROUP BY. Safe to run repeatedly: it replaces the whole table each time. Called by the refresh
 * pipeline after the snapshot is current.
 */
class Rebuild extends \Civi\Api4\Generic\AbstractAction {

  public function _run(\Civi\Api4\Generic\Result $result) {
    $entry = Config::entryReasonStatusIds();
    $exit = Config::exitReasonStatusIds();
    $list = fn($ids) => ($ids = array_map('intval', $ids)) ? implode(',', $ids) : '-1';
    $react = $list($entry['reactivated'] ?? []);
    $reinst = $list($entry['reinstated'] ?? []);
    $drop = $list($exit['dropped'] ?? []);
    $arch = $list($exit['archived'] ?? []);

    // Net basis: per-membership state at each fiscal year-end, walked with LAG (prior year-end) and
    // LEAD (next year-end) so entries, exits and transfers are classified without a self-join.
    $net = "WITH last_period AS (
        SELECT fiscal_year, MAX(period_end) AS pe FROM civicrm_membership_snapshot GROUP BY fiscal_year
      ),
      boundary AS (
        SELECT s.fiscal_year, s.membership_id, s.status_id, s.counts_as_member AS counted,
               CASE WHEN s.counts_as_member = 1 THEN s.membership_type_id END AS counted_type
        FROM civicrm_membership_snapshot s
        JOIN last_period lp ON lp.fiscal_year = s.fiscal_year AND lp.pe = s.period_end
        WHERE s.membership_id IS NOT NULL
      ),
      win AS (
        SELECT fiscal_year, membership_id, counted, counted_type,
               LAG(fiscal_year) OVER w AS prev_fy, LAG(counted) OVER w AS prev_counted,
               LAG(counted_type) OVER w AS prev_type, LAG(status_id) OVER w AS prev_status,
               LEAD(fiscal_year) OVER w AS next_fy, LEAD(counted) OVER w AS next_counted,
               LEAD(status_id) OVER w AS next_status
        FROM boundary WINDOW w AS (PARTITION BY membership_id ORDER BY fiscal_year)
      )
      SELECT fiscal_year, membership_id, counted_type AS membership_type_id, 'entered' AS flow,
             CASE WHEN prev_fy = fiscal_year - 1 AND prev_status IN ($react) THEN 'reactivated'
                  WHEN prev_fy = fiscal_year - 1 AND prev_status IN ($reinst) THEN 'reinstated'
                  ELSE 'new' END AS reason,
             'net' AS basis
      FROM win WHERE counted = 1 AND NOT (COALESCE(prev_fy, -1) = fiscal_year - 1 AND COALESCE(prev_counted, 0) = 1)
      UNION ALL
      SELECT fiscal_year + 1, membership_id, counted_type, 'exited',
             CASE WHEN next_fy = fiscal_year + 1 AND next_status IN ($drop) THEN 'dropped'
                  WHEN next_fy = fiscal_year + 1 AND next_status IN ($arch) THEN 'archived'
                  ELSE 'other' END,
             'net'
      FROM win WHERE counted = 1 AND NOT (COALESCE(next_fy, -1) = fiscal_year + 1 AND COALESCE(next_counted, 0) = 1)
      UNION ALL
      SELECT fiscal_year, membership_id, prev_type, 'transfer_out', NULL, 'net'
      FROM win WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type
      UNION ALL
      SELECT fiscal_year, membership_id, counted_type, 'transfer_in', NULL, 'net'
      FROM win WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type";

    // Gross basis: every counting boundary crossing during the year, from the event log. Counting is
    // whatever the snapshot recorded as counts_as_member. A transfer touches two categories.
    $counting = 'SELECT status_id FROM civicrm_membership_snapshot WHERE counts_as_member = 1 GROUP BY status_id';
    $entryReason = fn($col) => "CASE WHEN $col IN ($react) THEN 'reactivated' WHEN $col IN ($reinst) THEN 'reinstated' ELSE 'new' END";
    $exitReason = fn($col) => "CASE WHEN $col IN ($drop) THEN 'dropped' WHEN $col IN ($arch) THEN 'archived' ELSE 'other' END";
    $gross = "SELECT fiscal_year, membership_id, membership_type_id, 'entered', {$entryReason('from_status_id')}, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NULL AND (from_status_id IS NULL OR from_status_id NOT IN ($counting)) AND to_status_id IN ($counting)
      UNION ALL
      SELECT fiscal_year, membership_id, membership_type_id, 'exited', {$exitReason('to_status_id')}, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NULL AND from_status_id IN ($counting) AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
      UNION ALL
      SELECT fiscal_year, membership_id, from_type_id, 'transfer_out', NULL, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NOT NULL AND from_status_id IN ($counting) AND to_status_id IN ($counting)
      UNION ALL
      SELECT fiscal_year, membership_id, to_type_id, 'transfer_in', NULL, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NOT NULL AND from_status_id IN ($counting) AND to_status_id IN ($counting)
      UNION ALL
      SELECT fiscal_year, membership_id, from_type_id, 'exited', {$exitReason('to_status_id')}, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NOT NULL AND from_status_id IN ($counting) AND (to_status_id IS NULL OR to_status_id NOT IN ($counting))
      UNION ALL
      SELECT fiscal_year, membership_id, to_type_id, 'entered', {$entryReason('from_status_id')}, 'gross'
      FROM civicrm_membership_event
      WHERE from_type_id IS NOT NULL AND (from_status_id IS NULL OR from_status_id NOT IN ($counting)) AND to_status_id IN ($counting)";

    \CRM_Core_DAO::executeQuery('TRUNCATE TABLE civicrm_membership_year_end_flow');
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_membership_year_end_flow (fiscal_year, membership_id, membership_type_id, flow, reason, basis)
       $net UNION ALL $gross"
    );

    // Materialize the counted population per fiscal year-end (reconciliation opening/current), so the
    // views read these few rows instead of re-scanning the whole snapshot on every page query.
    if (\CRM_Core_DAO::singleValueQuery("SHOW TABLES LIKE 'civicrm_membership_year_end_count'")) {
      \CRM_Core_DAO::executeQuery('TRUNCATE TABLE civicrm_membership_year_end_count');
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_membership_year_end_count (fiscal_year, membership_type_id, counted, year_end_date)
         SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member), MAX(s.period_end)
         FROM civicrm_membership_snapshot s
         JOIN (SELECT fiscal_year, MAX(period_end) AS pe FROM civicrm_membership_snapshot GROUP BY fiscal_year) lp
           ON lp.fiscal_year = s.fiscal_year AND lp.pe = s.period_end
         GROUP BY s.fiscal_year, s.membership_type_id"
      );
    }

    $dimensions = $this->stampDimensions();

    $result[] = [
      'net' => (int) \CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_membership_year_end_flow WHERE basis = 'net'"),
      'gross' => (int) \CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_membership_year_end_flow WHERE basis = 'gross'"),
      'dimensions' => $dimensions,
    ];
  }

  /**
   * Stamp the active demographic dimensions onto the fact so a report can reconcile by any of them
   * (gender, geography, or a dimension added in the UI manager) with a plain GROUP BY.
   *
   * The values are copied straight from the snapshot's already-stamped dimension columns, keyed by
   * membership, rather than re-resolved from the contact. That guarantees a flow row carries exactly
   * the value the snapshot carries for the same membership, so a reconciliation sliced by a dimension
   * ties back to the snapshot sliced the same way. The snapshot stamps each dimension as the
   * contact's current value (identical across the membership's periods), so MAX() per membership is
   * that value and is robust to a period left unstamped by an incremental run.
   *
   * Columns are added to the fact on demand (like MembershipSnapshot.syncColumns does on the
   * snapshot), so no separate schema migration is needed when a dimension is configured.
   *
   * @return array<string> Dimension columns stamped this run.
   */
  private function stampDimensions(): array {
    $snapshotColumns = $this->tableColumns('civicrm_membership_snapshot');
    $factColumns = $this->tableColumns('civicrm_membership_year_end_flow');

    // Only dimensions that are active AND actually stamped on the snapshot can be copied.
    $columns = [];
    foreach (Config::activeDimensions() as $spec) {
      $col = $spec['column'];
      if (isset($snapshotColumns[strtolower($col)])) {
        $columns[$col] = $spec['sql_type'] ?: 'varchar(64)';
      }
    }
    if (!$columns) {
      return [];
    }

    foreach ($columns as $col => $type) {
      if (!isset($factColumns[strtolower($col)])) {
        \CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_membership_year_end_flow ADD COLUMN `$col` $type NULL");
      }
    }

    $picks = [];
    $sets = [];
    foreach (array_keys($columns) as $col) {
      $picks[] = "MAX(s.`$col`) AS `$col`";
      $sets[] = "f.`$col` = d.`$col`";
    }
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_membership_year_end_flow f
       JOIN (
         SELECT s.membership_id, ' . implode(', ', $picks) . '
         FROM civicrm_membership_snapshot s
         WHERE s.membership_id IS NOT NULL
         GROUP BY s.membership_id
       ) d ON d.membership_id = f.membership_id
       SET ' . implode(', ', $sets)
    );

    return array_keys($columns);
  }

  /**
   * Column names on a table, keyed lower-case for case-insensitive lookup.
   */
  private function tableColumns(string $table): array {
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT column_name AS column_name FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = %1",
      [1 => [$table, 'String']]
    );
    $columns = [];
    while ($dao->fetch()) {
      $columns[strtolower($dao->column_name)] = TRUE;
    }
    return $columns;
  }

}
