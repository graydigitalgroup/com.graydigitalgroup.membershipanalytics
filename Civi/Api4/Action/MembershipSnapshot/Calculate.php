<?php

namespace Civi\Api4\Action\MembershipSnapshot;

use CRM_Membershipanalytics_Config as Config;

/**
 * Build period snapshots by replaying the membership event log up to each period end.
 *
 * For each period boundary in [fromDate, toDate] at the configured cadence, capture
 * each membership's state as of period_end (type, status, status_bucket,
 * counts_as_member, term dates), classify movement vs. the prior period, and resolve
 * as-of demographics from MembershipContactDimension. Closed periods are immutable;
 * historical construction is bounded by transaction time (log_date) so later backdating
 * does not rewrite them (design §13.1).
 *
 * Safe to re-run: without $recalculate it only fills periods after the latest existing
 * one; with $recalculate it rebuilds the given range.
 */
class Calculate extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Start date for calculations. Keep constant across runs so periods align.
   *
   * @var string
   * @required
   */
  protected $fromDate;

  /**
   * Build periods up to this date. Defaults to now.
   *
   * @var string
   */
  protected $toDate = 'now';

  /**
   * Rebuild periods that already exist in the range.
   *
   * @var bool
   */
  protected $recalculate = FALSE;

  /**
   * Maximum number of periods to build in this run.
   *
   * @var int
   */
  protected $maxPeriods = 240;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $period = \Civi::settings()->get('membershipanalytics_snapshot_period') ?: 'month';

    if ($this->recalculate) {
      \Civi\Api4\MembershipSnapshot::delete(FALSE)
        ->addWhere('period:name', '=', $period)
        ->addWhere('period_start', '>=', $this->fromDate)
        ->addWhere('period_start', '<', $this->toDate)
        ->execute();
      $latest = NULL;
    }
    else {
      $latest = \Civi\Api4\MembershipSnapshot::get(FALSE)
        ->addWhere('period:name', '=', $period)
        ->addWhere('period_start', '>=', $this->fromDate)
        ->addWhere('period_start', '<', $this->toDate)
        ->addOrderBy('period_start', 'DESC')
        ->addSelect('period_start')
        ->setLimit(1)
        ->execute()
        ->first();
    }

    $periodStart = $latest
      ? $this->nextPeriodStart(new \DateTimeImmutable($latest['period_start']), $period)
      : new \DateTimeImmutable($this->fromDate);
    $stop = new \DateTimeImmutable($this->toDate);
    $runs = 0;

    while ($periodStart < $stop && $runs < $this->maxPeriods) {
      $result[] = $this->calcPeriod($periodStart, $period);
      $periodStart = $this->nextPeriodStart($periodStart, $period);
      $runs++;
    }
  }

  /**
   * First day of the next period.
   */
  protected function nextPeriodStart(\DateTimeImmutable $date, string $period): \DateTimeImmutable {
    switch ($period) {
      case 'month':
        return $date->modify('first day of next month');

      case 'week':
        return $date->modify('+1 week');

      default:
        throw new \CRM_Core_Exception("nextPeriodStart not implemented for period '{$period}'");
    }
  }

  /**
   * Last day of the period (period_end / the as-of instant).
   */
  protected function periodEnd(\DateTimeImmutable $periodStart, string $period): \DateTimeImmutable {
    return $this->nextPeriodStart($periodStart, $period)->modify('-1 day');
  }

  /**
   * Build one period's snapshot rows.
   *
   * @return array
   */
  protected function calcPeriod(\DateTimeImmutable $periodStart, string $period): array {
    $periodEnd = $this->periodEnd($periodStart, $period);
    $fiscalYear = Config::fiscalYearOf($periodEnd);
    $calendarYear = Config::calendarYearOf($periodEnd);

    // TODO (install+test pass): for each membership known as of period_end (bounded
    // by log_date <= period_end for immutability, design §13.1), resolve its
    // type/status as of period_end from the event log, set status_bucket +
    // counts_as_member via Config, classify movement vs. the prior period (including
    // reinstated/reactivated from activities), join MembershipContactDimension for
    // as-of demographics, and save the rows. Return a per-period summary.
    return [
      'period_start' => $periodStart->format('Y-m-d'),
      'period_end' => $periodEnd->format('Y-m-d'),
      'fiscal_year' => $fiscalYear,
      'calendar_year' => $calendarYear,
      'rows_written' => 0,
    ];
  }

}
