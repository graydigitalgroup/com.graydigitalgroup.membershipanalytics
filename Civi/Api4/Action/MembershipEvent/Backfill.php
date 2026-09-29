<?php

namespace Civi\Api4\Action\MembershipEvent;

/**
 * Rebuild the membership event log from civicrm_membership_log.
 *
 * Walks the core membership log per membership in chronological order and emits one
 * MembershipEvent per meaningful change:
 *  - first row for a membership          -> joined
 *  - membership_type_id changed          -> type_change (transfer), with from/to type
 *  - status_id changed                   -> dropped / archived (by bucket) else status_change
 *
 * Payment-driven reinstated/reactivated events are added by a separate pass that
 * cross-references a completed membership contribution at the transition date (see
 * design §9.1); go-forward they come from OrderCompletedSubscriber.
 *
 * Safe to re-run: bounded by fromDate/toDate; with $replace it clears the range first.
 * This is a rebuild from the log, so it should be run deliberately, not routinely.
 */
class Backfill extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Earliest modified_date to process. Defaults to the earliest membership log row.
   *
   * @var string|null
   */
  protected $fromDate;

  /**
   * Latest modified_date to process (inclusive-ish). Defaults to now.
   *
   * @var string
   */
  protected $toDate = 'now';

  /**
   * Delete existing backfilled events in the range before rebuilding.
   *
   * @var bool
   */
  protected $replace = FALSE;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $from = $this->fromDate ?: $this->earliestLogDate();
    $to = $this->toDate;

    if ($this->replace) {
      \Civi\Api4\MembershipEvent::delete(FALSE)
        ->addWhere('source:name', '=', 'log_backfill')
        ->addWhere('effective_date', '>=', $from)
        ->addWhere('effective_date', '<=', $to)
        ->execute();
    }

    // TODO (install+test pass): read civicrm_membership_log ordered by
    // membership_id, modified_date, id; diff consecutive rows per membership and
    // emit MembershipEvent rows (joined / type_change / dropped / archived /
    // status_change) with fiscal_year + calendar_year from Config, source =
    // log_backfill. Bulk-insert in chunks. Then run the payment-driven
    // reinstated/reactivated pass. Reconcile counts against civicrm_membership.
    $written = 0;

    $result[] = ['from' => $from, 'to' => $to, 'events_written' => $written];
  }

  /**
   * Earliest modified_date present in the core membership log.
   */
  protected function earliestLogDate(): string {
    $earliest = \CRM_Core_DAO::singleValueQuery('SELECT MIN(modified_date) FROM civicrm_membership_log');
    return $earliest ?: date('Y-m-d');
  }

}
