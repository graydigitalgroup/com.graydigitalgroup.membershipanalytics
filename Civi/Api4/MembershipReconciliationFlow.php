<?php

namespace Civi\Api4;

/**
 * Membership Reconciliation Flow (SQL View) — the reconciliation flows in long format.
 *
 * One row per fiscal_year × membership_type_id × flow, where flow is 'Entered' or 'Exited'
 * and value is that count. It is the wide MembershipReconciliation view unpivoted, so a chart
 * can group bars by flow (ChartKit's series type needs the grouping as a column, not as two
 * separate value columns).
 *
 * Client-agnostic; reads MembershipReconciliation, which already derives counting and the
 * fiscal year-end generically. Filtering and presentation are the report layer's job.
 *
 * @searchable secondary
 * @package Civi\Api4
 */
class MembershipReconciliationFlow extends Generic\SqlView {

  protected static function getEntityTitle(bool $plural = FALSE): string {
    return $plural ? ts('Membership Reconciliation Flows') : ts('Membership Reconciliation Flow');
  }

  /**
   * Allow read for any CiviCRM user; aggregate counts only, no contact-level data.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

  /**
   * Rebuild the view atomically on every Api4 entityTypes cache rebuild (mirrors the sibling
   * views; a single CREATE OR REPLACE avoids the drop-then-create race under concurrent flush).
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
      ['select' => 'ROW_NUMBER() OVER (ORDER BY recon.fiscal_year, recon.membership_type_id, recon.flow)', 'name' => 'id', 'data_type' => 'Integer'],
      ['select' => 'recon.fiscal_year', 'name' => 'fiscal_year', 'original_field' => 'MembershipSnapshot.fiscal_year'],
      ['select' => 'recon.fiscal_year_end', 'name' => 'fiscal_year_end', 'data_type' => 'Date', 'title' => ts('Fiscal Year End')],
      ['select' => 'recon.membership_type_id', 'name' => 'membership_type_id', 'original_field' => 'MembershipSnapshot.membership_type_id'],
      ['select' => 'recon.flow', 'name' => 'flow', 'data_type' => 'String', 'title' => ts('Flow')],
      ['select' => 'recon.value', 'name' => 'value', 'data_type' => 'Integer', 'title' => ts('Members')],
    ];
  }

  protected static function viewFrom(): string {
    return "FROM (
      SELECT fiscal_year, fiscal_year_end, membership_type_id, 'Entered' AS flow, entered AS value
      FROM civicrm_view_membership_reconciliation
      UNION ALL
      SELECT fiscal_year, fiscal_year_end, membership_type_id, 'Exited' AS flow, exited AS value
      FROM civicrm_view_membership_reconciliation
    ) recon";
  }

}
