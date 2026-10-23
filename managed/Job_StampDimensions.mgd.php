<?php

use CRM_Membershipanalytics_ExtensionUtil as E;

// On-demand job to apply dimension changes to the existing snapshot without rebuilding it.
//
// MembershipSnapshot.stampDimensions first syncs the snapshot's columns to the active dimension set
// (adding a column for any newly added dimension), then re-stamps every active dimension across all
// periods. An admin who adds or enables a dimension on the Membership Snapshot Dimensions screen runs
// this job (Administer → Scheduled Jobs → Run now) to populate it immediately; otherwise the monthly
// snapshot refresh picks it up.
//
// Inactive by default (run manually, not on a schedule). 'update' => 'unmodified' preserves UI edits.
return [
  [
    'name' => 'Cron:MembershipSnapshot.stampDimensions',
    'entity' => 'Job',
    'update' => 'unmodified',
    'params' => [
      'version' => 3,
      'name' => 'Membership Analytics: stamp dimensions',
      'description' => 'Syncs and re-stamps the configured snapshot dimensions. Run after adding or enabling a dimension.',
      'run_frequency' => 'Always',
      'api_entity' => 'MembershipSnapshot',
      'api_action' => 'stampDimensions',
      'parameters' => '{"version":4,"checkPermissions":false}',
      'is_active' => 0,
    ],
  ],
];
