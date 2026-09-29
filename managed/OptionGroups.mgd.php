<?php

use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Option groups backing the analytics entities. Values are ordered; their stored
 * integer `value` is the 1-based position, so keep the order stable once in use.
 */
$groups = [
  'membershipanalytics_period' => [
    'title' => E::ts('Membership Analytics Period'),
    'values' => ['month' => 'Month', 'week' => 'Week'],
  ],
  'membership_movement' => [
    'title' => E::ts('Membership Movement'),
    'values' => [
      'new' => 'New',
      'renewed' => 'Renewed',
      'continued' => 'Continued',
      'reactivated' => 'Reactivated',
      'reinstated' => 'Reinstated',
      'transferred_in' => 'Transferred In',
      'transferred_out' => 'Transferred Out',
      'dropped' => 'Dropped',
      'archived' => 'Archived',
    ],
  ],
  'membership_status_bucket' => [
    'title' => E::ts('Membership Status Bucket'),
    'values' => [
      'new' => 'New',
      'current' => 'Current',
      'grace' => 'Grace',
      'pending' => 'Pending',
      'dropped' => 'Dropped',
      'archived' => 'Archived',
      'cancelled' => 'Cancelled',
      'deceased' => 'Deceased',
      'lapsed' => 'Lapsed',
    ],
  ],
  'membership_event_type' => [
    'title' => E::ts('Membership Event Type'),
    'values' => [
      'joined' => 'Joined',
      'status_change' => 'Status Change',
      'type_change' => 'Type Change',
      'renewed' => 'Renewed',
      'continued' => 'Continued',
      'reactivated' => 'Reactivated',
      'reinstated' => 'Reinstated',
      'dropped' => 'Dropped',
      'archived' => 'Archived',
    ],
  ],
  'membershipanalytics_source' => [
    'title' => E::ts('Membership Analytics Source'),
    'values' => [
      'log_backfill' => 'Membership Log Backfill',
      'audit_backfill' => 'Audit Log Backfill',
      'hook' => 'Live Hook',
      'order_complete' => 'Order Complete',
      'activity' => 'Activity',
    ],
  ],
];

$items = [];
foreach ($groups as $groupName => $group) {
  $items[] = [
    'name' => 'OptionGroup_' . $groupName,
    'entity' => 'OptionGroup',
    'update' => 'always',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => $groupName,
        'title' => $group['title'],
        'is_reserved' => TRUE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ];
  $value = 1;
  foreach ($group['values'] as $optName => $optLabel) {
    $items[] = [
      'name' => 'OptionValue_' . $groupName . '_' . $optName,
      'entity' => 'OptionValue',
      'update' => 'always',
      'cleanup' => 'always',
      'params' => [
        'version' => 4,
        'values' => [
          'option_group_id:name' => $groupName,
          'name' => $optName,
          'label' => E::ts($optLabel),
          'value' => $value,
          'weight' => $value,
          'is_active' => TRUE,
        ],
        'match' => ['option_group_id', 'name'],
      ],
    ];
    $value++;
  }
}

return $items;
