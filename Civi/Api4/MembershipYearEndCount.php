<?php
declare(strict_types = 1);

namespace Civi\Api4;

/**
 * MembershipYearEndCount entity.
 *
 * Materialized counted population per fiscal year-end (see schema/MembershipYearEndCount.entityType.php).
 * Rebuilt by MembershipYearEndFlow.Rebuild; read by the reconciliation views for opening/current.
 *
 * Provided by the com.graydigitalgroup.membershipanalytics extension.
 *
 * @package Civi\Api4
 */
class MembershipYearEndCount extends Generic\DAOEntity {

  /**
   * Aggregate counts by category and fiscal year only — no contact-level data.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

}
