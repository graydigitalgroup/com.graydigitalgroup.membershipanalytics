<?php

namespace Civi\Api4\Action\MembershipYearEndFlow;

use CRM_Membershipanalytics_Config as Config;

/**
 * Rebuild the materialized reconciliation fact from the snapshot.
 *
 * For each membership, compares its counted state across consecutive fiscal year-ends (one sorted
 * window pass over the snapshot, not a self-join) and writes one row per net flow: entered, exited,
 * transfer_in, transfer_out, with the reason. This is the expensive part of reconciliation, computed
 * once here so the reports are a cheap indexed GROUP BY. Safe to run repeatedly: it replaces the
 * whole table each time. Called by the refresh pipeline after the snapshot is current.
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

    // Per-membership state at each fiscal year-end, walked with LAG (prior year-end) and LEAD (next
    // year-end) so entries, exits and transfers can be classified without a self-join.
    $flows = "WITH last_period AS (
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
                  ELSE 'new' END AS reason
      FROM win WHERE counted = 1 AND NOT (COALESCE(prev_fy, -1) = fiscal_year - 1 AND COALESCE(prev_counted, 0) = 1)
      UNION ALL
      SELECT fiscal_year + 1, membership_id, counted_type, 'exited',
             CASE WHEN next_fy = fiscal_year + 1 AND next_status IN ($drop) THEN 'dropped'
                  WHEN next_fy = fiscal_year + 1 AND next_status IN ($arch) THEN 'archived'
                  ELSE 'other' END
      FROM win WHERE counted = 1 AND NOT (COALESCE(next_fy, -1) = fiscal_year + 1 AND COALESCE(next_counted, 0) = 1)
      UNION ALL
      SELECT fiscal_year, membership_id, prev_type, 'transfer_out', NULL
      FROM win WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type
      UNION ALL
      SELECT fiscal_year, membership_id, counted_type, 'transfer_in', NULL
      FROM win WHERE counted = 1 AND prev_fy = fiscal_year - 1 AND prev_counted = 1 AND prev_type <> counted_type";

    \CRM_Core_DAO::executeQuery('TRUNCATE TABLE civicrm_membership_year_end_flow');
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_membership_year_end_flow (fiscal_year, membership_id, membership_type_id, flow, reason) $flows"
    );
    $rows = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_membership_year_end_flow');
    $result[] = ['rows' => $rows];
  }

}
