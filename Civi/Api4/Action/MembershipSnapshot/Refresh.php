<?php

namespace Civi\Api4\Action\MembershipSnapshot;

/**
 * Keep the membership analytics read-model current (the scheduled-maintenance entry point).
 *
 * Recomputes a trailing window of monthly snapshots so the most recent closed month is
 * finalized from the membership log and the open month reflects live membership state. On the
 * first run (empty snapshot table) it builds the full history instead of just the window, so a
 * fresh install becomes fully populated without a separate manual step.
 *
 * Thin wrapper over MembershipSnapshot.calculate; exists so the monthly job can pass a single
 * "keep it current" intent rather than compute a date window itself.
 */
class Refresh extends \Civi\Api4\Generic\AbstractAction {

  /**
   * How many trailing months to recompute each run. The window finalizes the most recent closed
   * month from the membership log and refreshes the still-open month from live data. Ignored on
   * the first run, which builds the full history.
   *
   * @var int
   */
  protected $months = 2;

  /**
   * Also rebuild the MembershipEvent table from the membership log before snapshotting. Off by
   * default; the event log is historical and only needs an occasional rebuild, not one every cycle.
   *
   * @var bool
   */
  protected $rebuildEvents = FALSE;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $months = max(1, (int) $this->months);
    $out = [];

    if ($this->rebuildEvents) {
      $out['events'] = \Civi\Api4\MembershipEvent::backfill(FALSE)
        ->setReplace(TRUE)
        ->execute()
        ->first();
    }

    $haveSnapshots = (bool) \CRM_Core_DAO::singleValueQuery('SELECT 1 FROM civicrm_membership_snapshot LIMIT 1');

    $calc = \Civi\Api4\MembershipSnapshot::calculate(FALSE);
    if ($haveSnapshots) {
      // Recompute only the trailing window: first-of-this-month minus (months-1) gives the start
      // of the oldest month to rebuild, through today.
      $from = (new \DateTimeImmutable('first day of this month'))
        ->modify('-' . ($months - 1) . ' months')
        ->format('Y-m-d');
      $calc->setRecalculate(TRUE)->setFromDate($from)->setToDate('now');
    }
    // First run: leave defaults so calculate() builds the entire history.

    $out['snapshots'] = $calc->execute()->first();

    // Rebuild the materialized reconciliation fact from the now-current snapshot, so reconciliation
    // reports read a small pre-computed table instead of windowing the whole snapshot per page view.
    $out['year_end_flow'] = \Civi\Api4\MembershipYearEndFlow::rebuild(FALSE)->execute()->first();

    $result[] = $out;
  }

}
