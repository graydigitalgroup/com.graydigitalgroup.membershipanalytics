<?php

namespace Civi\Api4\Action\MembershipSnapshot;

use CRM_Membershipanalytics_Config as Config;

/**
 * Stamp demographic dimensions onto snapshot rows (Option B: config-driven columns).
 *
 * Reads the historized dimensions (the membershipanalytics_historized_dimensions setting)
 * and, for each one that has a column mapping, writes the contact's value onto its snapshot
 * column. Dimensions are grouped by source so each source is one UPDATE: 'contact' values
 * come straight from civicrm_contact, 'address' values from the contact's primary address.
 *
 * This stamps the contact's CURRENT value onto every period. For a stable dimension like
 * gender that equals the historical value; for geography it is a present-day approximation
 * until as-of reconstruction from the change logs is added. Re-running refreshes the stamp,
 * so it is safe to run after each snapshot calculate.
 */
class StampDimensions extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Limit stamping to rows in this fiscal year (NULL = all rows).
   *
   * @var int|null
   */
  protected $fiscalYear;

  /**
   * Only fill rows where the column is still NULL, instead of overwriting every row.
   *
   * @var bool
   */
  protected $onlyMissing = FALSE;

  public function _run(\Civi\Api4\Generic\Result $result) {
    // Make sure every active dimension has a column before stamping into it.
    \Civi\Api4\MembershipSnapshot::syncColumns(FALSE)->execute();

    // Group the active dimensions by their JOIN so each distinct source is one UPDATE
    // (gender from the contact; geography from the primary address; a custom-field table as
    // its own join). Dimensions that share a join — like the address columns — stamp together.
    $byJoin = [];
    foreach (Config::activeDimensions() as $dim => $spec) {
      $byJoin[$spec['join']][$dim] = $spec;
    }

    $stamped = [];
    foreach ($byJoin as $join => $dims) {
      $affected = $this->stampJoin($join, $dims);
      foreach ($dims as $dim => $spec) {
        $stamped[$dim] = ['column' => $spec['column'], 'rows' => $affected];
      }
    }

    $result[] = ['dimensions' => $stamped];
  }

  /**
   * Run one UPDATE that stamps every dimension sharing the given JOIN.
   */
  protected function stampJoin(string $join, array $dims): int {
    $sets = [];
    $missingGuards = [];
    foreach ($dims as $spec) {
      $col = $spec['column'];
      $sets[] = "s.`$col` = {$spec['expr']}";
      $missingGuards[] = "s.`$col` IS NULL";
    }

    $where = [];
    if ($this->onlyMissing) {
      $where[] = '(' . implode(' OR ', $missingGuards) . ')';
    }
    if ($this->fiscalYear !== NULL) {
      $where[] = 's.fiscal_year = ' . (int) $this->fiscalYear;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // Count the rows in scope before updating (ROW_COUNT() is unreliable through the DAO
    // wrapper, and after an onlyMissing pass the scope count would be zero).
    $affected = (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM civicrm_membership_snapshot s $join" . $whereSql
    );

    $sql = "UPDATE civicrm_membership_snapshot s
            $join
            SET " . implode(', ', $sets) . $whereSql;
    \CRM_Core_DAO::executeQuery($sql);

    return $affected;
  }

}
