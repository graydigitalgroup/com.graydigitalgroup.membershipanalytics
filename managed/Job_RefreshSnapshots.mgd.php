<?php

use CRM_Membershipanalytics_ExtensionUtil as E;

// Monthly maintenance job that keeps the membership analytics snapshots current.
//
// It recomputes the trailing two months each run: the most recent closed month is finalized
// from the membership log, and the still-open month is refreshed from live membership state.
// The first run on a fresh install builds the full history instead.
//
// The job calls the APIv4 action MembershipSnapshot.refresh directly: the CiviCRM job runner
// dispatches through civicrm_api(), which routes to APIv4 when the job's `parameters` declare
// version 4 (JSON form here so months stays an integer). checkPermissions is off because this
// is a trusted server-side maintenance job run from cron.
//
// 'update' => 'unmodified' lets file changes flow to this job until an admin edits it in the UI,
// after which the UI copy is preserved.
return [
  [
    'name' => 'Cron:MembershipSnapshot.refresh',
    'entity' => 'Job',
    'update' => 'unmodified',
    'params' => [
      'version' => 3,
      'name' => 'Membership Analytics: refresh snapshots',
      'description' => 'Recomputes the trailing months of membership snapshots so point-in-time and year-end reports stay current.',
      'run_frequency' => 'Monthly',
      'api_entity' => 'MembershipSnapshot',
      'api_action' => 'refresh',
      'parameters' => '{"version":4,"checkPermissions":false,"months":2}',
      'is_active' => 1,
    ],
  ],
];
