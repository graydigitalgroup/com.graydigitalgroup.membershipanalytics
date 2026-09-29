<?php

use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Configuration for the membership analytics extension. These are a report-time
 * interpretation layer — the event table captures everything regardless of these
 * values, so changing a setting and re-running the backfill re-applies it to history.
 */
return [
  'membershipanalytics_reportable_membership_types' => [
    'name' => 'membershipanalytics_reportable_membership_types',
    'title' => E::ts('Reportable Membership Types'),
    'description' => E::ts('Membership types counted as "membership" in reports. The event log and snapshot still record all types; reports filter to this set. Empty = all active types.'),
    'group_name' => 'domain',
    'type' => 'Array',
    'html_type' => 'select',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipanalytics_reporting_year_basis' => [
    'name' => 'membershipanalytics_reporting_year_basis',
    'title' => E::ts('Reporting Year Basis'),
    'description' => E::ts('Whether reports group by fiscal year (from core fiscalYearStart) or calendar year.'),
    'group_name' => 'domain',
    'type' => 'String',
    'html_type' => 'select',
    'pseudoconstant' => [
      'callback' => 'CRM_Membershipanalytics_Config::getReportingYearBases',
    ],
    'default' => 'fiscal',
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipanalytics_snapshot_period' => [
    'name' => 'membershipanalytics_snapshot_period',
    'title' => E::ts('Snapshot Period'),
    'description' => E::ts('Cadence at which snapshots are built. Monthly is the default; weekly can be enabled and backfilled later.'),
    'group_name' => 'domain',
    'type' => 'String',
    'html_type' => 'select',
    'default' => 'month',
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipanalytics_counting_status_ids' => [
    'name' => 'membershipanalytics_counting_status_ids',
    'title' => E::ts('Counting Statuses'),
    'description' => E::ts('Statuses that count a member as present. Empty = all statuses where core is_current_member = 1.'),
    'group_name' => 'domain',
    'type' => 'Array',
    'html_type' => 'select',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipanalytics_status_classification' => [
    'name' => 'membershipanalytics_status_classification',
    'title' => E::ts('Status Classification'),
    'description' => E::ts('Map of membership status id => lifecycle bucket name. Empty = derived from status names/flags at runtime.'),
    'group_name' => 'domain',
    'type' => 'Array',
    'html_type' => 'text',
    'default' => [],
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'membershipanalytics_historized_dimensions' => [
    'name' => 'membershipanalytics_historized_dimensions',
    'title' => E::ts('Historized Dimensions'),
    'description' => E::ts('Which contact/custom fields get as-of history in the dimension table. Defaults to core demographic fields; sites add their own custom fields here. Identity fields (name, email) are deliberately excluded.'),
    'group_name' => 'domain',
    'type' => 'Array',
    'html_type' => 'select',
    'default' => ['gender', 'country', 'state', 'city'],
    'is_domain' => 1,
    'is_contact' => 0,
  ],
];
