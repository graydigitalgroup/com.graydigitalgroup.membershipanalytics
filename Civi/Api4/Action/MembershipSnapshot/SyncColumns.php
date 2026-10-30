<?php

namespace Civi\Api4\Action\MembershipSnapshot;

use CRM_Membershipanalytics_Config as Config;

/**
 * Ensure the snapshot has a column for every active demographic dimension (Option B manager).
 *
 * The set of dimensions is config-driven (the historized dimensions plus their column map,
 * which a site extends through the dimensionColumnMap event). For each active dimension that
 * has no column yet, this adds one using the type from the map. It only adds columns; it never
 * drops one, so deactivating a dimension leaves its data in place. Run it before stamping (which
 * it is, automatically) and whenever a new dimension is registered.
 */
class SyncColumns extends \Civi\Api4\Generic\AbstractAction {

  const TABLE = 'civicrm_membership_snapshot';

  public function _run(\Civi\Api4\Generic\Result $result) {
    $existing = $this->existingColumns();
    $added = [];
    foreach (Config::activeDimensions() as $dim => $spec) {
      $col = $spec['column'];
      if (!isset($existing[strtolower($col)])) {
        $type = $spec['sql_type'] ?: 'varchar(64)';
        \CRM_Core_DAO::executeQuery(
          'ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . $col . '` ' . $type . ' NULL'
        );
        $existing[strtolower($col)] = TRUE;
        $added[$dim] = $col;
      }
    }
    $result[] = ['added' => $added];
  }

  /**
   * Column names already on the table, keyed lower-case for case-insensitive lookup.
   */
  protected function existingColumns(): array {
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT column_name AS column_name FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = %1",
      [1 => [self::TABLE, 'String']]
    );
    $cols = [];
    while ($dao->fetch()) {
      $cols[strtolower($dao->column_name)] = TRUE;
    }
    return $cols;
  }

}
