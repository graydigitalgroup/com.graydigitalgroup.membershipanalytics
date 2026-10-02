<?php

namespace Civi\Api4\Action\MembershipSnapshot;

use CRM_Membershipanalytics_Config as Config;

/**
 * Build period snapshots of membership state.
 *
 * For each period (monthly by default) from fromDate to toDate, record every
 * membership's state as of period_end — the latest civicrm_membership_log row with
 * modified_date <= period_end — with its status bucket and counts-as-member flag.
 * Bounding by modified_date keeps closed periods stable: a change recorded after a
 * period does not alter that period (design §13.1).
 *
 * Demographic dimensions are intentionally NOT written here — they are stamped by a
 * later step once the Option A/B storage decision is made. This builds membership
 * state only, which is identical in both options.
 *
 * Safe to re-run: without $recalculate it resumes after the latest existing period;
 * with $recalculate it rebuilds the given range.
 */
class Calculate extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Start date. Defaults to the first of the month of the earliest membership log row.
   * Keep it constant across runs so periods align.
   *
   * @var string|null
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
  protected $maxPeriods = 1000;

  /**
   * How many snapshot rows to buffer before each bulk insert.
   *
   * @var int
   */
  protected $batchSize = 2000;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    $period = \Civi::settings()->get('membershipanalytics_snapshot_period') ?: 'month';
    if ($period !== 'month') {
      throw new \CRM_Core_Exception("Only the 'month' period is implemented; got '{$period}'.");
    }

    $from = $this->fromDate ?: $this->defaultFromDate();
    $to = date('Y-m-d', strtotime($this->toDate) ?: time());

    // Resolve option-value ids and config once, passed through to each period.
    $fys = Config::fiscalYearStart();
    $ctx = [
      'periodId' => $this->optionValues('membershipanalytics_period')[$period],
      'bucketId' => $this->optionValues('membership_status_bucket'),
      'classification' => Config::getStatusClassification(),
      'countingIds' => array_fill_keys(Config::countingStatusIds(), TRUE),
      'fStartM' => $fys['M'],
      'fStartD' => $fys['d'],
      'today' => date('Y-m-d'),
    ];

    if ($this->recalculate) {
      \CRM_Core_DAO::executeQuery(
        'DELETE FROM civicrm_membership_snapshot WHERE period = %1 AND period_start >= %2 AND period_start <= %3',
        [1 => [$ctx['periodId'], 'Integer'], 2 => [$from, 'String'], 3 => [$to, 'String']]
      );
      $latest = NULL;
    }
    else {
      $latest = \CRM_Core_DAO::singleValueQuery(
        'SELECT MAX(period_start) FROM civicrm_membership_snapshot WHERE period = %1 AND period_start >= %2 AND period_start <= %3',
        [1 => [$ctx['periodId'], 'Integer'], 2 => [$from, 'String'], 3 => [$to, 'String']]
      );
    }

    $periodStart = $latest
      ? $this->nextPeriodStart(new \DateTimeImmutable($latest))
      : (new \DateTimeImmutable($from))->modify('first day of this month');
    $stop = new \DateTimeImmutable($to);

    $runs = 0;
    $totalRows = 0;
    $periods = [];
    while ($periodStart <= $stop && $runs < $this->maxPeriods) {
      $written = $this->calcPeriod($periodStart, $ctx);
      $totalRows += $written;
      $periods[] = ['period_end' => $this->periodEnd($periodStart)->format('Y-m-d'), 'rows' => $written];
      $periodStart = $this->nextPeriodStart($periodStart);
      $runs++;
    }

    $result[] = ['from' => $from, 'to' => $to, 'periods' => count($periods), 'total_rows' => $totalRows];
  }

  /**
   * First day of the month of the earliest membership log row.
   */
  protected function defaultFromDate(): string {
    $min = \CRM_Core_DAO::singleValueQuery('SELECT MIN(modified_date) FROM civicrm_membership_log');
    return $min ? date('Y-m-01', strtotime($min)) : date('Y-m-01');
  }

  protected function nextPeriodStart(\DateTimeImmutable $date): \DateTimeImmutable {
    return $date->modify('first day of next month');
  }

  protected function periodEnd(\DateTimeImmutable $periodStart): \DateTimeImmutable {
    return $periodStart->modify('last day of this month');
  }

  /**
   * Build one period's snapshot rows. Returns the row count written.
   */
  protected function calcPeriod(\DateTimeImmutable $periodStart, array $ctx): int {
    $periodEnd = $this->periodEnd($periodStart);
    $peStr = $periodEnd->format('Y-m-d');
    $psStr = $periodStart->format('Y-m-d');
    $cy = (int) $periodEnd->format('Y');
    $mo = (int) $periodEnd->format('n');
    $dy = (int) $periodEnd->format('j');
    $fy = ($mo > $ctx['fStartM'] || ($mo === $ctx['fStartM'] && $dy >= $ctx['fStartD'])) ? $cy + 1 : $cy;

    if ($peStr >= $ctx['today']) {
      // Open/current period: use live membership state, so "now" matches the system
      // exactly (the log can lag when a status is set without writing a log row).
      $dao = \CRM_Core_DAO::executeQuery(
        'SELECT m.id AS mid, m.contact_id AS cid, m.status_id AS sid,
                m.membership_type_id AS tid, m.start_date AS sd, m.end_date AS ed, m.join_date AS jd
         FROM civicrm_membership m'
      );
    }
    else {
      // Closed period: state as recorded on/before period_end (the latest log row).
      $dao = \CRM_Core_DAO::executeQuery(
        'SELECT ml.membership_id AS mid, m.contact_id AS cid, ml.status_id AS sid,
                ml.membership_type_id AS tid, ml.start_date AS sd, ml.end_date AS ed, m.join_date AS jd
         FROM (SELECT membership_id, MAX(id) AS max_id
               FROM civicrm_membership_log
               WHERE modified_date <= %1
               GROUP BY membership_id) latest
         JOIN civicrm_membership_log ml ON ml.id = latest.max_id
         LEFT JOIN civicrm_membership m ON m.id = ml.membership_id',
        [1 => [$peStr . ' 23:59:59', 'String']]
      );
    }

    $buffer = [];
    $written = 0;
    while ($dao->fetch()) {
      $sid = (int) $dao->sid;
      $bucket = $ctx['classification'][$sid] ?? 'lapsed';
      $counts = isset($ctx['countingIds'][$sid]) ? 1 : 0;
      $buffer[] = sprintf(
        "(%d,%s,%d,%d,%d,%d,%d,'%s','%s',%d,%d,%s,%s,%s)",
        (int) $dao->mid,
        $dao->cid !== NULL ? (int) $dao->cid : 'NULL',
        (int) $dao->tid,
        $sid,
        $ctx['bucketId'][$bucket],
        $counts,
        $ctx['periodId'],
        $psStr,
        $peStr,
        $fy,
        $cy,
        $this->sqlDate($dao->jd),
        $this->sqlDate($dao->sd),
        $this->sqlDate($dao->ed)
      );
      if (count($buffer) >= $this->batchSize) {
        $this->flush($buffer);
        $written += count($buffer);
        $buffer = [];
      }
    }
    if ($buffer) {
      $this->flush($buffer);
      $written += count($buffer);
    }
    return $written;
  }

  /**
   * A date value as a quoted SQL literal, or NULL.
   */
  protected function sqlDate($value): string {
    return ($value === NULL || $value === '') ? 'NULL' : "'" . \CRM_Core_DAO::escapeString($value) . "'";
  }

  protected function flush(array $rows): void {
    $sql = 'INSERT INTO civicrm_membership_snapshot
      (membership_id, contact_id, membership_type_id, status_id, status_bucket, counts_as_member,
       period, period_start, period_end, fiscal_year, calendar_year, join_date, start_date, end_date)
      VALUES ' . implode(',', $rows);
    \CRM_Core_DAO::executeQuery($sql);
  }

  protected function optionValues(string $group): array {
    $out = [];
    $ovs = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('name', 'value')
      ->addWhere('option_group_id:name', '=', $group)
      ->execute();
    foreach ($ovs as $ov) {
      $out[$ov['name']] = (int) $ov['value'];
    }
    return $out;
  }

}
