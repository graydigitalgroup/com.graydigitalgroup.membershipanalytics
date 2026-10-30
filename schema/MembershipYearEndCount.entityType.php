<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Materialized counted population at each fiscal year-end, per category.
 *
 * One row per fiscal_year × membership_type_id with the counted-member total and the year-end date.
 * This is the opening/current side of reconciliation; computing it means scanning the whole snapshot
 * for the year-end rows, so it is materialized once per refresh (by MembershipYearEndFlow.Rebuild)
 * and the reconciliation views read these ~dozens of rows instead of re-scanning the snapshot on
 * every page query.
 */
return [
  'name' => 'MembershipYearEndCount',
  'table' => 'civicrm_membership_year_end_count',
  'class' => 'CRM_Membershipanalytics_DAO_MembershipYearEndCount',
  'getInfo' => fn() => [
    'title' => E::ts('Membership Year-End Count'),
    'title_plural' => E::ts('Membership Year-End Counts'),
    'description' => E::ts('Counted members at each fiscal year-end per category (reconciliation opening/current).'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique MembershipYearEndCount ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'fiscal_year' => [
      'title' => E::ts('Fiscal Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Fiscal year'),
    ],
    'membership_type_id' => [
      'title' => E::ts('Membership Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Category'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'counted' => [
      'title' => E::ts('Counted Members'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Counted members at the fiscal year-end'),
    ],
    'year_end_date' => [
      'title' => E::ts('Fiscal Year End'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('The fiscal year-end date (latest snapshot period in the fiscal year)'),
    ],
  ],
  'getIndices' => fn() => [
    'index_fiscal_year_type' => [
      'fields' => ['fiscal_year' => TRUE, 'membership_type_id' => TRUE],
      'unique' => FALSE,
    ],
  ],
  'getPaths' => fn() => [],
];
