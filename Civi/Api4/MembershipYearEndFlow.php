<?php
declare(strict_types = 1);

namespace Civi\Api4;

/**
 * MembershipYearEndFlow entity.
 *
 * Materialized reconciliation fact (see schema/MembershipYearEndFlow.entityType.php). Rebuilt by
 * MembershipYearEndFlow.Rebuild as part of the refresh pipeline; read by reconciliation reports.
 *
 * Provided by the com.graydigitalgroup.membershipanalytics extension.
 *
 * @package Civi\Api4
 */
class MembershipYearEndFlow extends Generic\DAOEntity {

  /**
   * Aggregate reconciliation flows by category and fiscal year — no contact-level data beyond the
   * membership id, so general back-office access is enough.
   */
  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

}
