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
    $dimensions = Config::historizedDimensions();
    $map = Config::dimensionColumnMap();

    // Keep only configured dimensions that have a column mapping, grouped by source.
    $bySource = [];
    foreach ($dimensions as $dim) {
      if (isset($map[$dim])) {
        $bySource[$map[$dim]['source']][$dim] = $map[$dim];
      }
    }

    $stamped = [];
    foreach ($bySource as $source => $dims) {
      $affected = $this->stampSource($source, $dims);
      foreach ($dims as $dim => $spec) {
        $stamped[$dim] = ['column' => $spec['column'], 'rows' => $affected];
      }
    }

    $result[] = ['dimensions' => $stamped];
  }

  /**
   * Run one UPDATE that stamps every dimension drawn from the given source.
   */
  protected function stampSource(string $source, array $dims): int {
    $join = $this->sourceJoin($source);
    if ($join === NULL) {
      return 0;
    }

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

  /**
   * The JOIN clause that exposes a source's row as alias `src` for each snapshot row.
   */
  protected function sourceJoin(string $source): ?string {
    switch ($source) {
      case 'contact':
        return 'JOIN civicrm_contact src ON src.id = s.contact_id';

      case 'address':
        // Primary address; LEFT so a contact without one stamps NULL rather than dropping the row.
        return 'LEFT JOIN civicrm_address src ON src.contact_id = s.contact_id AND src.is_primary = 1';

      default:
        return NULL;
    }
  }

}
