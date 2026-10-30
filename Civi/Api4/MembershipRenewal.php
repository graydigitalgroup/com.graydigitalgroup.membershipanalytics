<?php

namespace Civi\Api4;

/**
 * Membership Renewal Mix (SQL View) — how each category's year-end members got there.
 *
 * One row per fiscal_year × membership_type_id splitting the counted members at the fiscal
 * year-end into:
 *  - renewed: counted at both this and the prior year-end in the same category, with the term
 *    extended during the year (end_date advanced) — i.e. they paid to renew.
 *  - continued: counted at both year-ends in the same category with no term extension — still
 *    inside an existing (often multi-year or no-end) term, e.g. Honorary/Emeritus/Junior.
 *  - added: everyone else counted this year-end (first-time joins, reactivations, reinstatements
 *    and transfers into the category) = total − renewed − continued.
 *
 * renewed + continued + added = total_counted, so the three columns are a full composition of
 * the year-end count. "Renewed" keys off the term end_date advancing rather than an event label,
 * so a member who renews twice in a year is still counted once.
 *
 * Client-agnostic: counting and the fiscal year-end come from the snapshot; a report filters to
 * its reportable categories. Mirrors the other analytics views.
 *
 * @searchable secondary
 * @package Civi\Api4
 */
class MembershipRenewal extends Generic\SqlView {

  protected static function getEntityTitle(bool $plural = FALSE): string {
    return $plural ? ts('Membership Renewal Mixes') : ts('Membership Renewal Mix');
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
   * The per-year-end aggregates are chained CTEs (see aggCte) so MySQL 8 materializes each once
   * instead of re-aggregating the snapshot for every reference of the self-joined counted_ye.
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
      ['select' => 'ROW_NUMBER() OVER (ORDER BY mix.fiscal_year, mix.membership_type_id)', 'name' => 'id', 'data_type' => 'Integer'],
      ['select' => 'mix.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      ['select' => 'mix.year_end_date', 'name' => 'fiscal_year_end', 'data_type' => 'Date', 'title' => ts('Fiscal Year End')],
      ['select' => 'mix.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'mix.total_counted', 'name' => 'total_counted', 'data_type' => 'Integer', 'title' => ts('Total Members')],
      ['select' => 'mix.renewed', 'name' => 'renewed', 'data_type' => 'Integer', 'title' => ts('Renewed')],
      ['select' => 'mix.continued', 'name' => 'continued', 'data_type' => 'Integer', 'title' => ts('Continued')],
      ['select' => '(mix.total_counted - mix.renewed - mix.continued)', 'name' => 'added', 'data_type' => 'Integer', 'title' => ts('New / Added')],
    ];
  }

  protected static function viewFrom(): string {
    return "FROM (
      SELECT ye.fiscal_year, ye.membership_type_id, ye.year_end_date,
             ye.counted AS total_counted,
             COALESCE(p.renewed, 0) AS renewed,
             COALESCE(p.continued, 0) AS continued
      FROM year_end ye
      LEFT JOIN pairs p
        ON p.fiscal_year = ye.fiscal_year AND p.membership_type_id = ye.membership_type_id
    ) mix";
  }

  /**
   * The per-year-end aggregates, defined once as chained CTEs so MySQL 8 materializes each a single
   * time. The prior-year comparison self-joins counted_ye (cur vs prev); inlining it as plain derived
   * tables made MySQL 8 re-aggregate the whole snapshot for every reference — tens of seconds. MariaDB
   * optimizes either form.
   *
   * - last_period: the latest snapshot date in each fiscal year (the year-end instant).
   * - counted_ye: one counted row per membership at its fiscal year-end, with its term end_date.
   * - year_end: counted members per category at each fiscal year-end.
   * - pairs: members counted at both this and the prior year-end in the same category, split into
   *   renewed (term end_date advanced) vs continued (unchanged).
   */
  private static function aggCte(): string {
    return "last_period AS (
        SELECT fiscal_year, MAX(period_end) AS pe
        FROM civicrm_membership_snapshot GROUP BY fiscal_year
      ),
      counted_ye AS (
        SELECT s.fiscal_year, s.membership_id, s.membership_type_id, s.end_date
        FROM civicrm_membership_snapshot s
        JOIN last_period ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
        WHERE s.counts_as_member = 1 AND s.membership_id IS NOT NULL
      ),
      year_end AS (
        SELECT s.fiscal_year, s.membership_type_id, SUM(s.counts_as_member) AS counted,
               MAX(s.period_end) AS year_end_date
        FROM civicrm_membership_snapshot s
        JOIN last_period ON last_period.fiscal_year = s.fiscal_year AND last_period.pe = s.period_end
        GROUP BY s.fiscal_year, s.membership_type_id
      ),
      pairs AS (
        SELECT cur.fiscal_year, cur.membership_type_id,
               COALESCE(SUM(cur.end_date > prev.end_date), 0) AS renewed,
               COUNT(*) - COALESCE(SUM(cur.end_date > prev.end_date), 0) AS continued
        FROM counted_ye cur
        JOIN counted_ye prev
          ON prev.membership_id = cur.membership_id
         AND prev.membership_type_id = cur.membership_type_id
         AND prev.fiscal_year = cur.fiscal_year - 1
        GROUP BY cur.fiscal_year, cur.membership_type_id
      )";
  }

}
