<?php

namespace Civi\Api4\Action\MembershipEvent;

use CRM_Membershipanalytics_Config as Config;

/**
 * Rebuild the membership event log from civicrm_membership_log.
 *
 * Walks the core membership log per membership in chronological order and emits one
 * MembershipEvent per meaningful change:
 *  - first row for a membership -> joined
 *  - membership_type_id changed -> type_change (transfer), with from/to type
 *  - status_id changed (type unchanged) -> classified by the before/after status bucket:
 *      new bucket = dropped        -> dropped
 *      new bucket = archived       -> archived
 *      returns to a counting bucket from archived -> reinstated
 *      returns to a counting bucket from dropped/lapsed/cancelled -> reactivated
 *      otherwise                   -> status_change
 *
 * Every row also stores membership_type_id (the log row's type as of the event) so flows
 * attribute to a category; from_type_id/to_type_id carry a transfer's endpoints. The
 * baked event_type above is a convenience label — reconciliation derives inflow/outflow
 * at report time from the from/to status counting boundary, so it stays correct when the
 * counting-status configuration changes.
 *
 * Go-forward, reinstated/reactivated come from OrderCompletedSubscriber (CNS layer)
 * with the payment linked; this backfill approximates them from the log transition.
 *
 * Safe to re-run: bounded by fromDate/toDate; with $replace it clears the range first.
 */
class Backfill extends \Civi\Api4\Generic\AbstractAction {

  /**
   * Earliest modified_date to process. Defaults to the earliest membership log row.
   *
   * @var string|null
   */
  protected $fromDate;

  /**
   * Latest modified_date to process (inclusive). Defaults to now.
   *
   * @var string
   */
  protected $toDate = 'now';

  /**
   * Retained for API compatibility. A backfill now always clears this source's prior events before
   * rebuilding (see _run), so a re-run never duplicates regardless of this flag.
   *
   * @var bool
   */
  protected $replace = FALSE;

  /**
   * How many event rows to buffer before each bulk insert.
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
    $from = $this->fromDate ?: $this->earliestLogDate();
    $to = date('Y-m-d H:i:s', strtotime($this->toDate) ?: time());

    $sourceId = $this->optionValues('membershipanalytics_source')['log_backfill'] ?? NULL;
    if (!$sourceId) {
      // Fail fast rather than writing events with source 0: on a fresh install the backfill must not run
      // until the membershipanalytics_source option values have synced. Flush caches after enabling the
      // extension, then retry.
      throw new \CRM_Core_Exception('Membership event source "log_backfill" is not configured yet; flush caches after enabling the extension, then retry the backfill.');
    }
    // A backfill always regenerates this source's events from the full membership log, so clear the prior
    // backfill events first; otherwise a re-run appends a duplicate set. Also clear any legacy orphans
    // written with source 0/NULL before the source option values resolved. Unconditional (not gated on
    // $replace) so a re-run is always safe; a range-bounded delete would miss joined events dated before
    // the range (they use the membership's join_date). Live hook/order_complete/activity sources are left
    // untouched.
    \CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_membership_event WHERE source = %1 OR source = 0 OR source IS NULL',
      [1 => [$sourceId, 'Integer']]
    );

    $classification = Config::getStatusClassification();
    $eventTypeId = $this->optionValues('membership_event_type');
    $fys = Config::fiscalYearStart();
    $fStartM = $fys['M'];
    $fStartD = $fys['d'];
    $counting = ['new' => 1, 'current' => 1, 'grace' => 1];

    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT ml.membership_id AS mid, m.contact_id AS cid, ml.status_id AS sid,
              ml.membership_type_id AS tid, ml.modified_date AS md
       FROM civicrm_membership_log ml
       LEFT JOIN civicrm_membership m ON m.id = ml.membership_id
       WHERE ml.modified_date >= %1 AND ml.modified_date <= %2
       ORDER BY ml.membership_id, ml.modified_date, ml.id',
      [1 => [$from, 'String'], 2 => [$to, 'String']]
    );

    $buffer = [];
    $written = 0;
    $byType = [];
    $prevMid = NULL;
    $prevSid = NULL;
    $prevTid = NULL;

    while ($dao->fetch()) {
      $mid = (int) $dao->mid;
      $cid = $dao->cid !== NULL ? (string) (int) $dao->cid : 'NULL';
      $sid = (int) $dao->sid;
      $tid = (int) $dao->tid;
      $md = $dao->md;

      // Each event: [eventType, fromStatus, toStatus, fromType, toType, effectiveDate]
      $events = [];
      if ($mid !== $prevMid) {
        // Date the entry at the first log row, NOT the membership's join_date. The snapshot is
        // built from the log, so a membership first appears (and first counts) at its first log
        // row; the entry event has to land in the same period or the reconciliation identity
        // breaks. join_date disagrees in two ways that caused exactly that: it is reset to the
        // conversion date when a Medical Student membership is converted to Junior (emitting a
        // second, later "join" for an already-counted member), and it predates the log for
        // members who joined before logging began.
        $events[] = ['joined', NULL, $sid, NULL, $tid, $md];
      }
      else {
        $typeChanged = ($prevTid !== $tid);
        $statusChanged = ($prevSid !== $sid);
        if ($typeChanged) {
          $events[] = ['type_change', $prevSid, $sid, $prevTid, $tid, $md];
        }
        elseif ($statusChanged) {
          $prevBucket = $classification[$prevSid] ?? 'lapsed';
          $newBucket = $classification[$sid] ?? 'lapsed';
          if ($newBucket === 'dropped') {
            $et = 'dropped';
          }
          elseif ($newBucket === 'archived') {
            $et = 'archived';
          }
          elseif (isset($counting[$newBucket]) && $prevBucket === 'archived') {
            $et = 'reinstated';
          }
          elseif (isset($counting[$newBucket]) && in_array($prevBucket, ['dropped', 'lapsed', 'cancelled'], TRUE)) {
            $et = 'reactivated';
          }
          else {
            $et = 'status_change';
          }
          $events[] = [$et, $prevSid, $sid, NULL, NULL, $md];
        }
      }

      foreach ($events as [$etName, $fromS, $toS, $fromT, $toT, $effDate]) {
        $cy = (int) substr($effDate, 0, 4);
        $mo = (int) substr($effDate, 5, 2);
        $dy = (int) substr($effDate, 8, 2);
        $fy = ($mo > $fStartM || ($mo === $fStartM && $dy >= $fStartD)) ? $cy + 1 : $cy;
        $buffer[] = sprintf(
          "(%d,%s,%d,%d,%s,%s,%s,%s,'%s',%d,%d,%d,NOW())",
          $mid,
          $cid,
          $tid,
          $eventTypeId[$etName],
          $fromS === NULL ? 'NULL' : (int) $fromS,
          $toS === NULL ? 'NULL' : (int) $toS,
          $fromT === NULL ? 'NULL' : (int) $fromT,
          $toT === NULL ? 'NULL' : (int) $toT,
          \CRM_Core_DAO::escapeString($effDate),
          $fy,
          $cy,
          $sourceId
        );
        $byType[$etName] = ($byType[$etName] ?? 0) + 1;
      }

      $prevMid = $mid;
      $prevSid = $sid;
      $prevTid = $tid;

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

    $result[] = ['from' => $from, 'to' => $to, 'events_written' => $written, 'by_type' => $byType];
  }

  /**
   * Bulk-insert a batch of pre-formatted VALUES tuples.
   */
  protected function flush(array $rows): void {
    $sql = 'INSERT INTO civicrm_membership_event
      (membership_id, contact_id, membership_type_id, event_type, from_status_id, to_status_id,
       from_type_id, to_type_id, effective_date, fiscal_year, calendar_year, source, created_date)
      VALUES ' . implode(',', $rows);
    \CRM_Core_DAO::executeQuery($sql);
  }

  /**
   * name => stored integer value, for an option group.
   */
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

  /**
   * Earliest modified_date present in the core membership log.
   */
  protected function earliestLogDate(): string {
    $earliest = \CRM_Core_DAO::singleValueQuery('SELECT MIN(modified_date) FROM civicrm_membership_log');
    return $earliest ?: date('Y-m-d');
  }

}
