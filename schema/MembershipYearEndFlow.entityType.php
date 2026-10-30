<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Materialized reconciliation fact: one row per membership per fiscal-year boundary flow.
 *
 * Each row is a single net flow for one membership at one fiscal year-end — entered, exited,
 * transferred in or transferred out — with the reason (new/reactivated/reinstated on entry;
 * dropped/archived/other on exit). The expensive cross-period comparison (comparing each
 * membership's counted state across consecutive year-ends) is computed once per refresh by
 * MembershipYearEndFlow.Rebuild and stored here, so reconciliation reports are a cheap indexed
 * GROUP BY rather than a window over the whole snapshot on every page view.
 *
 * Grain is per membership, so a report can slice these flows by any stamped dimension the same
 * way it slices the snapshot.
 */
return [
  'name' => 'MembershipYearEndFlow',
  'table' => 'civicrm_membership_year_end_flow',
  'class' => 'CRM_Membershipanalytics_DAO_MembershipYearEndFlow',
  'getInfo' => fn() => [
    'title' => E::ts('Membership Year-End Flow'),
    'title_plural' => E::ts('Membership Year-End Flows'),
    'description' => E::ts('Per-membership fiscal year-end reconciliation flows (entered/exited/transferred), computed once per refresh.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique MembershipYearEndFlow ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'fiscal_year' => [
      'title' => E::ts('Fiscal Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Fiscal year the flow is attributed to'),
    ],
    'membership_id' => [
      'title' => E::ts('Membership ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Membership; nullable so history survives membership deletion'),
      'entity_reference' => [
        'entity' => 'Membership',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'membership_type_id' => [
      'title' => E::ts('Membership Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Category the flow applies to (the entering category on entry/transfer-in, the leaving category on exit/transfer-out)'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'flow' => [
      'title' => E::ts('Flow'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'description' => E::ts('entered | exited | transfer_in | transfer_out'),
      'pseudoconstant' => [
        'callback' => 'CRM_Membershipanalytics_Config::reconFlowOptions',
      ],
    ],
    'reason' => [
      'title' => E::ts('Reason'),
      'sql_type' => 'varchar(16)',
      'input_type' => 'Select',
      'description' => E::ts('Entry: new/reactivated/reinstated. Exit: dropped/archived/other. Null for transfers.'),
      'pseudoconstant' => [
        'callback' => 'CRM_Membershipanalytics_Config::reconReasonOptions',
      ],
    ],
  ],
  'getIndices' => fn() => [
    'index_fiscal_year_type' => [
      'fields' => ['fiscal_year' => TRUE, 'membership_type_id' => TRUE],
      'unique' => FALSE,
    ],
    'index_membership' => [
      'fields' => ['membership_id' => TRUE],
      'unique' => FALSE,
    ],
    'index_flow' => [
      'fields' => ['flow' => TRUE],
      'unique' => FALSE,
    ],
  ],
  'getPaths' => fn() => [],
];
