<?php
declare(strict_types = 1);
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
final class CRM_Membershipanalytics_Upgrader extends \CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * Note that if a file is present sql\auto_install that will run regardless of this hook.
   */
  // public function install(): void {
  //   $this->executeSqlFile('sql/my_install.sql');
  // }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  // public function postInstall(): void {
  //  $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
  //    'return' => array("id"),
  //    'name' => "customFieldCreatedViaManagedHook",
  //  ));
  //  civicrm_api3('Setting', 'create', array(
  //    'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
  //  ));
  // }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
   * Note that if a file is present sql\auto_uninstall that will run regardless of this hook.
   */
  // public function uninstall(): void {
  //   $this->executeSqlFile('sql/my_uninstall.sql');
  // }

  /**
   * Example: Run a simple query when a module is enabled.
   */
  // public function enable(): void {
  //  CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a simple query when a module is disabled.
   */
  // public function disable(): void {
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4200(): bool {
  //   $this->ctx->log->info('Applying update 4200');
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
  //   CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
  //   return TRUE;
  // }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4201(): bool {
  //   $this->ctx->log->info('Applying update 4201');
  //   // this path is relative to the extension base dir
  //   $this->executeSqlFile('sql/upgrade_4201.sql');
  //   return TRUE;
  // }

  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4202(): bool {
  //   $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

  //   $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
  //   $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
  //   $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
  //   return TRUE;
  // }
  // public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  // public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  // public function processPart3($arg5) { sleep(10); return TRUE; }

  /**
   * Add the as-of membership_type_id column to the event log so flows attribute to a
   * category without re-joining the core membership log. Guarded so it is a no-op if the
   * schema mixin has already added the column from the entityType definition. Populate it
   * by re-running MembershipEvent.Backfill.
   */
  public function upgrade_1100(): bool {
    $this->ctx->log->info('Applying update 1100 - add membership_type_id to civicrm_membership_event');
    $table = 'civicrm_membership_event';
    $hasColumn = (bool) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = %1 AND column_name = 'membership_type_id'",
      [1 => [$table, 'String']]
    );
    if (!$hasColumn) {
      CRM_Core_DAO::executeQuery(
        "ALTER TABLE `$table` ADD COLUMN `membership_type_id` int unsigned NULL
           COMMENT 'Membership type as of the event' AFTER `contact_id`"
      );
    }
    $hasIndex = (bool) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM information_schema.statistics
       WHERE table_schema = DATABASE() AND table_name = %1 AND index_name = 'index_type_effective'",
      [1 => [$table, 'String']]
    );
    if (!$hasIndex) {
      CRM_Core_DAO::executeQuery(
        "ALTER TABLE `$table` ADD INDEX `index_type_effective` (`membership_type_id`, `effective_date`)"
      );
    }
    return TRUE;
  }

  /**
   * Add the demographic dimension columns to civicrm_membership_snapshot so they can be
   * stamped (Option B). The schema definition carries them; this adds any that are not yet
   * on the installed table. Populate them with MembershipSnapshot.stampDimensions.
   */
  public function upgrade_1200(): bool {
    $this->ctx->log->info('Applying update 1200 - add demographic columns to civicrm_membership_snapshot');
    $table = 'civicrm_membership_snapshot';
    $columns = [
      'gender_id' => "int unsigned NULL COMMENT 'Contact gender as of the period' AFTER `movement_to_type_id`",
      'country_id' => "int unsigned NULL COMMENT 'Primary address country as of the period' AFTER `gender_id`",
      'state_province_id' => "int unsigned NULL COMMENT 'Primary address state/province as of the period' AFTER `country_id`",
      'city' => "varchar(64) NULL COMMENT 'Primary address city as of the period' AFTER `state_province_id`",
    ];
    foreach ($columns as $name => $definition) {
      $exists = (bool) CRM_Core_DAO::singleValueQuery(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = %1 AND column_name = %2",
        [1 => [$table, 'String'], 2 => [$name, 'String']]
      );
      if (!$exists) {
        CRM_Core_DAO::executeQuery("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
      }
    }
    $hasIndex = (bool) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM information_schema.statistics
       WHERE table_schema = DATABASE() AND table_name = %1 AND index_name = 'index_gender_fiscal_year'",
      [1 => [$table, 'String']]
    );
    if (!$hasIndex) {
      CRM_Core_DAO::executeQuery(
        "ALTER TABLE `$table` ADD INDEX `index_gender_fiscal_year` (`gender_id`, `fiscal_year`)"
      );
    }
    return TRUE;
  }

  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  /**
   * Add the materialized reconciliation fact table (MembershipYearEndFlow). It is populated by
   * MembershipYearEndFlow.Rebuild as part of the refresh pipeline, so the table is created empty here.
   */
  public function upgrade_1300(): bool {
    $this->ctx->log->info('Applying update 1300 - add civicrm_membership_year_end_flow');
    if (!CRM_Core_DAO::singleValueQuery("SHOW TABLES LIKE 'civicrm_membership_year_end_flow'")) {
      CRM_Core_DAO::executeQuery("CREATE TABLE civicrm_membership_year_end_flow (
        id int unsigned NOT NULL AUTO_INCREMENT,
        fiscal_year int NULL COMMENT 'Fiscal year the flow is attributed to',
        membership_id int unsigned NULL COMMENT 'FK to Membership',
        membership_type_id int unsigned NULL COMMENT 'Category the flow applies to',
        flow varchar(16) NULL COMMENT 'entered | exited | transfer_in | transfer_out',
        reason varchar(16) NULL COMMENT 'Entry/exit reason; null for transfers',
        PRIMARY KEY (id),
        INDEX index_fiscal_year_type (fiscal_year, membership_type_id),
        INDEX index_membership (membership_id),
        INDEX index_flow (flow)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    return TRUE;
  }

  // public function upgrade_4203(): bool {
  //   $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

  //   $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
  //   $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
  //   for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
  //     $endId = $startId + self::BATCH_SIZE - 1;
  //     $title = E::ts('Upgrade Batch (%1 => %2)', array(
  //       1 => $startId,
  //       2 => $endId,
  //     ));
  //     $sql = '
  //       UPDATE civicrm_contribution SET foobar = apple(banana()+durian)
  //       WHERE id BETWEEN %1 and %2
  //     ';
  //     $params = array(
  //       1 => array($startId, 'Integer'),
  //       2 => array($endId, 'Integer'),
  //     );
  //     $this->addTask($title, 'executeSql', $sql, $params);
  //   }
  //   return TRUE;
  // }

}
