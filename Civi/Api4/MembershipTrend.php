<?php

namespace Civi\Api4;

/**
 * Membership Trend (SQL View) — the reusable period-over-period comparison.
 *
 * One row per (period, membership category, status bucket), carrying that period's count
 * alongside the aligned prior-month and prior-year counts, so growth and percentage change
 * are available to SearchKit/ChartKit without per-report SQL. Built once over the snapshot
 * read-model; every "how did this move vs. last month / last year" report reuses it.
 *
 * Grain: one row per period_end × membership_type_id × status_bucket. The prior-month and
 * prior-year rows are matched on the same category+bucket at the month-end one month / one
 * year earlier (LAST_DAY handles month-length and leap-year alignment).
 *
 * Rolling up (totals across buckets or categories): sum the COUNT columns — `members`,
 * `counted`, `prev_month_counted`, `prev_year_counted` — and compute the percentage from
 * those sums in a SearchKit calculated field. The `*_pct` columns here are per-row (grain)
 * convenience values and must NOT be averaged across a rollup, since a ratio of sums is not
 * the sum of ratios.
 *
 * Demographic dimensions (gender/geography/Fellow) are deferred to the Option A/B decision;
 * when added to the snapshot they extend this view's grain the same way.
 *
 * @searchable secondary
 * @package Civi\Api4
 */
class MembershipTrend extends Generic\SqlView {

  protected static function getEntityTitle(bool $plural = FALSE): string {
    return $plural ? ts('Membership Trends') : ts('Membership Trend');
  }

  /**
   * Allow read for any CiviCRM user; this view holds only aggregate counts by category, status
   * bucket and period — no contact-level data — so it needs no stronger restriction than general
   * back-office access. Write actions are not applicable to a view and keep the restrictive default.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

  /**
   * Rebuild the view atomically on every Api4 entityTypes cache rebuild.
   *
   * The base handler does DROP VIEW then CREATE VIEW in two statements, which race under
   * concurrent cache rebuilds: one process recreates the view, a second — past its own no-op
   * DROP — then fatals with 1050 "already exists" on its CREATE. The view stays valid (the
   * loser only logs), so it is harmless noise, but a single CREATE OR REPLACE avoids the
   * drop-then-create window entirely. The SELECT/FROM assembly mirrors the parent.
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
      // Surrogate row id. The view is read-only and re-queried each time, so a ROW_NUMBER over
      // a stable ordering is sufficient for Api4/SearchKit row identity.
      ['select' => 'ROW_NUMBER() OVER (ORDER BY cur.period_end, cur.membership_type_id, cur.status_bucket)', 'name' => 'id', 'data_type' => 'Integer'],
      // Reuse the snapshot entity's field metadata (pseudoconstants + FK) for these columns.
      ['select' => 'cur.period', 'name' => 'period', 'original_field' => 'MembershipSnapshot.period'],
      ['select' => 'cur.period_end', 'name' => 'period_end', 'original_field' => 'MembershipSnapshot.period_end'],
      ['select' => 'cur.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      ['select' => 'cur.calendar_year', 'name' => 'calendar_year', 'original_field' => 'MembershipSnapshot.calendar_year'],
      ['select' => 'cur.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'cur.status_bucket', 'name' => 'status_bucket', 'original_field' => 'MembershipSnapshot.status_bucket'],
      // This period.
      ['select' => 'cur.members', 'name' => 'members', 'data_type' => 'Integer', 'title' => ts('Members (all statuses)')],
      ['select' => 'cur.counted', 'name' => 'counted', 'data_type' => 'Integer', 'title' => ts('Counted Members')],
      // Aligned prior-month figures.
      ['select' => 'COALESCE(pm.members, 0)', 'name' => 'prev_month_members', 'data_type' => 'Integer', 'title' => ts('Members (prev month)')],
      ['select' => 'COALESCE(pm.counted, 0)', 'name' => 'prev_month_counted', 'data_type' => 'Integer', 'title' => ts('Counted (prev month)')],
      // Aligned prior-year figures.
      ['select' => 'COALESCE(py.members, 0)', 'name' => 'prev_year_members', 'data_type' => 'Integer', 'title' => ts('Members (prev year)')],
      ['select' => 'COALESCE(py.counted, 0)', 'name' => 'prev_year_counted', 'data_type' => 'Integer', 'title' => ts('Counted (prev year)')],
      // Grain-level changes and percentages (see class note: do not average across a rollup).
      ['select' => '(cur.counted - COALESCE(pm.counted, 0))', 'name' => 'mom_change', 'data_type' => 'Integer', 'title' => ts('MoM Change')],
      ['select' => '(cur.counted - COALESCE(py.counted, 0))', 'name' => 'yoy_change', 'data_type' => 'Integer', 'title' => ts('YoY Change')],
      ['select' => 'ROUND(100 * (cur.counted - pm.counted) / NULLIF(pm.counted, 0), 1)', 'name' => 'mom_pct', 'data_type' => 'Float', 'title' => ts('MoM %')],
      ['select' => 'ROUND(100 * (cur.counted - py.counted) / NULLIF(py.counted, 0), 1)', 'name' => 'yoy_pct', 'data_type' => 'Float', 'title' => ts('YoY %')],
    ];
  }

  protected static function viewFrom(): string {
    $agg = self::aggSubquery();
    return "FROM $agg cur
      LEFT JOIN $agg pm
        ON pm.membership_type_id = cur.membership_type_id
       AND pm.status_bucket = cur.status_bucket
       AND pm.period = cur.period
       AND pm.period_end = LAST_DAY(cur.period_end - INTERVAL 1 MONTH)
      LEFT JOIN $agg py
        ON py.membership_type_id = cur.membership_type_id
       AND py.status_bucket = cur.status_bucket
       AND py.period = cur.period
       AND py.period_end = LAST_DAY(cur.period_end - INTERVAL 1 YEAR)";
  }

  /**
   * The per-period aggregate used three times (current, prior month, prior year). One row per
   * period_end × category × bucket, counting all rows and the counted-as-member subset.
   */
  private static function aggSubquery(): string {
    return "(SELECT period, period_end, fiscal_year, calendar_year, membership_type_id, status_bucket,
                    COUNT(*) AS members,
                    COALESCE(SUM(counts_as_member), 0) AS counted
             FROM civicrm_membership_snapshot
             GROUP BY period, period_end, fiscal_year, calendar_year, membership_type_id, status_bucket)";
  }

}
