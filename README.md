# Membership Analytics

`com.graydigitalgroup.membershipanalytics`

An [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

Membership reporting in CiviCRM is hard to do over time: the membership record only holds its *current* state, so questions like "how many Active members did we have at each fiscal year-end?" or "how many joined, renewed, lapsed and came back last year?" mean reconstructing history by hand. This extension keeps that history for you.

It records every membership change as an event, rolls those events up into a monthly point-in-time **snapshot** (one row per membership per period, with category, status and demographics as of that period), and exposes a set of SQL-view read-models for trend and reconciliation reporting. Everything is a normal APIv4 entity, so you build the actual reports in SearchKit.

## What's built

**Entities (stored tables)**

- **MembershipEvent** — an event-sourced log of membership changes (join, renewal, status change, category change), with the membership type as of the event.
- **MembershipSnapshot** — one row per membership per period (month). Captures `membership_type_id`, `status_id`, a classified `status_bucket`, `movement` vs. the prior period, `fiscal_year`/`calendar_year`, term dates, whether it `counts_as_member`, and a configurable set of contact **dimensions** (gender, geography, and anything else you add).

**Read-models (SQL views, queryable via APIv4 / SearchKit)**

- **MembershipTrend** — counts per category/status per period with year-over-year and month-over-month comparisons.
- **MembershipReconciliation** / **MembershipReconciliationNet** — opening + entered − exited = current, per fiscal year per category, on a gross (every movement) and net (year-end position) basis. Both carry an entered/exited reason breakdown (new, reactivated, reinstated / dropped, archived).
- **MembershipReconciliationFlow** / **MembershipReconciliationNetFlow** — the same reconciliation in long (one-row-per-flow) format, handy for charts.
- **MembershipRenewal** — renewed / continued / added counts per category per fiscal year.

**Actions, jobs and settings**

- APIv4 actions to build and maintain the data (see [Building the data](#building-the-data)).
- A monthly scheduled job that keeps snapshots current.
- A UI-managed option group for choosing which contact dimensions get stamped onto the snapshot.

## Requirements

CiviCRM 5.78+. Charts in the example reports use the `chart_kit` extension, but the data and SearchKit tables work without it.

## Installation

Install like any extension — clone into your extensions directory and enable it:

```bash
cd <your-extensions-dir>
git clone https://github.com/graydigitalgroup/com.graydigitalgroup.membershipanalytics.git
cv ext:enable com.graydigitalgroup.membershipanalytics
```

Enabling creates the tables, views, option groups and the scheduled job. It does **not** populate history yet — do that once, below.

## Building the data

Run these once after install (via `cv api4` on the CLI, or **Administer → Scheduled Jobs / the API Explorer**). They are safe to run again; re-running updates rather than duplicates.

1. **Build the event log from existing memberships:**

   ```bash
   cv api4 MembershipEvent.Backfill '{}'
   ```

   Options: `fromDate`, `toDate` (default `now`), `replace` (rebuild from scratch, default false), `batchSize` (default 2000).

2. **Calculate the snapshots from those events:**

   ```bash
   cv api4 MembershipSnapshot.Calculate '{}'
   ```

   Options: `fromDate`, `toDate` (default `now`), `recalculate` (redo existing periods, default false), `maxPeriods` (default 1000), `batchSize` (default 2000), `stampDimensions` (fill dimension columns as it goes, default true).

After that, the **Refresh Membership Snapshots** scheduled job keeps things current (by default it refreshes the last `months = 2`; set `rebuildEvents = true` to rebuild events first). You can also run it by hand:

```bash
cv api4 MembershipSnapshot.Refresh '{}'
```

## Using it for custom reports

Build reports in **SearchKit** against these entities — they behave like any other APIv4 entity, so you can filter, group and aggregate, then show the result as a table or a ChartKit chart.

- For **point-in-time / historical** questions, search **MembershipSnapshot**. Filter to the period you want (`period_end`, or `fiscal_year` for year-end), then group and count.
- For **trend and reconciliation** questions, search the read-models (**MembershipTrend**, **MembershipReconciliation**, **MembershipRenewal**, …) which already do the year-over-year math.

Useful MembershipSnapshot fields:

| Field | Meaning |
| --- | --- |
| `period_end` | The as-of date (last day of the period). |
| `fiscal_year`, `calendar_year` | Year containing `period_end`. |
| `membership_type_id` | Category as of the period. |
| `status_id` | CiviCRM status as of the period. |
| `status_bucket` | Classified lifecycle bucket (new / current / grace / dropped / archived / …). |
| `counts_as_member` | Whether the row counts toward membership (per the counting setting). |
| `movement` | Change vs. the prior period (new / continued / renewed / reactivated / reinstated / dropped / archived / transferred). |
| `gender_id`, `country_id`, `state_province_id`, `city` | Default contact dimensions, as of the period. |
| *(your dimensions)* | Any dimension you add (see below) appears here as its own field. |

**Example — Active members at each fiscal year-end, by category:** search MembershipSnapshot, filter `status_bucket` = Current and one row per membership at year-end (`period_end` = the fiscal year-end date), group by `fiscal_year` and `membership_type_id`, aggregate `COUNT`.

### A note on packaged reports

If ready-made SearchKit reports are shipped as managed records (for example by a companion extension), CiviCRM may overwrite UI edits to them on cache rebuild. To tweak a packaged report, use SearchKit's **Save a copy** and edit the copy, or change it in code. Reports you build from scratch in the UI are yours and are never overwritten.

## Adding a dimension (metadata)

A "dimension" is a contact attribute stamped onto each snapshot row so you can report membership broken down by it. The set of dimensions is **UI-managed** — no code needed to add one.

1. Go to **Administer → Option Groups → Membership Snapshot Dimensions** (option group `membershipanalytics_dimensions`). (A companion extension could surface this as a friendlier dedicated admin screen; this extension manages dimensions through the option group.)
2. Add an **option value**. The **label** is the display name; the **value** is the **field key**, which can be:
   - a **contact field** name — e.g. `job_title`, `gender_id`;
   - a **custom field** as `custom_<id>` — e.g. `custom_90`; or
   - a custom field as `<CustomGroupName>.<FieldName>`.
3. Make sure the option value is **active**. Deactivating one stops future stamping but leaves the data already collected.

Then add the column and fill it:

```bash
cv api4 MembershipSnapshot.SyncColumns '{}'      # add/remove snapshot columns to match the dimension list
cv api4 MembershipSnapshot.StampDimensions '{}'  # populate them (options: fiscalYear, onlyMissing)
```

The monthly refresh job also syncs and stamps, so a new dimension is filled on the next run even if you skip the manual step. Once stamped, the dimension is a regular field on **MembershipSnapshot** and immediately available in SearchKit.

Behind the scenes `Config::activeDimensions()` resolves each field key to a column and its source (a contact column, or a join to the custom-field table), `SyncColumns` reconciles the physical columns, and `StampDimensions` fills them per period.

> **As-of accuracy:** gender is stable, so its stamped value is accurate for past periods. Geography (country/state/city) is stamped from the contact's *current* primary address; true per-period historical geography (from change logs) is a possible later refinement.

## Known issues

- History must be built once after install (see [Building the data](#building-the-data)); reports are empty until then.
- Historical geography reflects the contact's current address, not the address as of each past period (see the note above).

## License

[AGPL-3.0](LICENSE.txt).
