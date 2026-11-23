=== Workflow Captain ===
Contributors: captainsuite
Tags: automation, workflow, woocommerce, events
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automation toolkit of the Captain Suite: store events, build workflows in a visual editor and run them reliably.

== Description ==

Workflow Captain connects events from the plugins of the Captain Suite with workflows that the site owner builds.

**Events**

* stores every event once per site and detects duplicates
* retries temporary failures on a fixed schedule; failed events can be queued again from the admin
* prevents loops between workflows (causation chain, depth at most 3)
* offers the interface where adapters from add-ons register

**Workflows**

* visual editor with trigger, conditions (then/else), waits, actions and end; usable with the keyboard, with a list view
* test run with sample data and no side effects
* draft and publish: every published version stays unchanged for the runs that use it
* actions: message to the site owner, message to a user, set and remove a tag, add a note
* runs with steps, cancel, and resume after a decision
* export and import of single workflows as JSON, duplicate, archive
* limits for parallel runs, fair distribution, emergency pause; on request, runs for the same object or the same user run one after the other

**Built-in triggers**

* WordPress: user registered, post published (public post types only). Only IDs and status are passed on, never name, email address, login, title or content.
* WooCommerce (version 11.2.2 or later, HPOS and legacy order tables): order created, paid, status changed, refunded, cancelled. Status, amounts in the smallest currency unit, currency and IDs are passed on, never addresses, email, names, IP address, customer note or coupon codes.
* Schedule: daily, weekly or monthly at a time in the site's time zone. A missed time is caught up at most once.
* Inbound triggers: an add-on can register triggers with fixed, typed fields. Workflow Captain checks every call again and stores it like any other event. Accepting calls from outside (signed webhooks, n8n) is not part of the free plugin.

**Captain Suite and custom connectors (with Workflow Captain Pro)**

* Pass Captain, Voucher Captain, Affiliate Captain and the vendor's license server deliver their events through adapters that come with the add-on Workflow Captain Pro. A Captain product also needs its own Pro add-on. The compatibility matrix lists which versions work together (see FAQ).
* Custom connectors are separate plugins that register under a public contract. They run when Workflow Captain Pro is active and must be enabled under "Custom connectors". Each connector checks its own license.
* Without an adapter, new events of a provider are kept as paused. Nothing is deleted and nothing is caught up on its own.

**Operations**

* worker through Action Scheduler when available, otherwise WP-Cron; WP-CLI commands for system cron, migration, verification and diagnostics
* Site Health checks, diagnostics download without personal data
* own capabilities: `manage_workflow_captain`, `edit_workflows`, `activate_workflows`, `view_workflow_logs`, `retry_workflow_runs`, `manage_workflow_integrations`
* audit log of interventions: capabilities, emergency pause, settings, requeued events, cancelled runs, published, paused and archived workflows
* versioned database upgrade: an interrupted upgrade is safely repeated, after a downgrade nothing is changed

Workflow Captain prints nothing on public pages and registers no REST routes, shortcodes or sitemaps.

The admin interface is currently in German.

== Installation ==

1. Upload and activate the plugin. The tables are created on activation (on multisite, per site).
2. Check limits, retention and capabilities under **Workflow Captain → Einstellungen** (settings).
3. If WP-Cron is disabled: run `wp workflow-captain process` every minute from the system cron.

== Frequently Asked Questions ==

= Does a workflow replace the emails of my plugins? =

No. Every core plugin keeps its emails. A workflow only sends additional messages. The editor shows which email of the core plugin goes out for the same occasion anyway.

= Is a message sent twice when the worker stops? =

No. If it is unclear whether a message already went out, the run stops and a person decides.

= What happens if I go back to an older version of the plugin? =

If the database schema is newer than the plugin, the plugin processes nothing and changes nothing until the newer version is installed again.

= What happens if an update is interrupted? =

The table upgrade remembers its progress. If it is interrupted, the worker waits, and after ten minutes the next call in the admin, by cron or `wp workflow-captain migrate` repeats the upgrade from the last finished step. Nothing is deleted.

= Do workflows run in the order of the events? =

Not guaranteed. Several runs can run at the same time. If you need an order, choose "runs one after the other" at the trigger: then a new run waits per object (for example per order) or per user until the older one has finished.

= Which versions of the Captain plugins work together? =

The compatibility matrix lists, per plugin, the version and the contract version from which events arrive: https://github.com/hastla007/wp-workflowmaster/blob/main/docs/KOMPATIBILITAET.md. An adapter only registers with a matching contract; otherwise the page **Workflow Captain → Provider** shows why.

= What do I send to support? =

Use the button "Diagnose herunterladen" (download diagnostics) under **Workflow Captain → Ereignisse** (events) or `wp workflow-captain diagnostics`. The file contains versions, the state of schema and worker, registered adapters and counts per state, but no personal data, no event contents and no secrets. **Tools → Site Health** shows the same checks.

== Privacy ==

Stored events and runs contain IDs, status, amounts and times, no codes, email addresses, names or free text. The user ID of whoever triggered an event is cleared once no run waits for it. Workflow Captain registers with the WordPress personal data export and erasure tools. Finished events are deleted after 30 days, failed and paused events after 90 days. Finished, failed and cancelled runs with their step log are deleted 30 days after they end; runs that still wait, are paused or need an action are kept until they end. The site owner can set each period between 1 and 180 days. Tags and notes that workflows set on an object are not removed by these periods; the change log keeps the last 50 changes (adjustable from 10 to 500) with the user ID.

How the data flows:

1. A plugin (WordPress, WooCommerce, a Captain plugin through its adapter, a custom connector) reports an event. Workflow Captain checks it and rejects events with forbidden fields (codes, email, names, addresses, free text).
2. The event is stored in a table of the site itself, with the user ID of whoever triggered it, if any.
3. A run copies the event. Steps store only their state and a shortened result.
4. A message to a user looks up the email address only when sending and does not store it.
5. Tags and notes contain only the text the site owner sets in the workflow.
6. Apart from the emails a workflow sends through WordPress, nothing leaves the site. Data for external services (webhooks, n8n) is only sent by an add-on and only after the site owner has set it up.

== External services ==

None. Workflow Captain does not call any external service.

== Screenshots ==

1. Visual editor: condition with a group ("A and (B or C)"), origin at the node, header with trigger, provider and last run.
2. Schedule as trigger: weekly, with the next three times in the site's time zone.
3. Overview: runs of the last 30 days, decisions, errors, worker state, provider status and emergency pause.
4. Workflows with their last run and the errors of the last 30 days.
5. Runs with filters by state and period, with paging.
6. A run: correlation, timeline of the steps, redacted event data and export without personal data.
7. Events of a provider with filters by state and paging.
8. Schedules: rule, next time and last fired.
9. Settings "Status": schema, database upgrade, worker and audit log of interventions.

== Changelog ==

= 0.2.0 =
* Minimum versions: PHP 8.5 and WordPress 7.0; below that the plugin stays inactive with a notice. The WooCommerce adapter needs WooCommerce 11.2.2. Site Health recommends an update when the database server is older than MySQL 9.7 or MariaDB 12.3 (no block).
* Workflow engine: workflows, versions, runs, steps, conditions, waits, actions of version 1.
* Visual editor with test run, condition form, placeholders, zoom and a hint about the emails of the core plugins.
* Limits for parallel runs, fairness, serialisation, heartbeat, emergency pause.
* Settings (general, execution, notifications, privacy, roles and capabilities, status), overview page, Site Health.
* Export, import, duplicate and archive workflows; WP-CLI commands; Action Scheduler when available.
* Triggers for WordPress, WooCommerce, schedules and inbound triggers of add-ons; registration of custom connectors.
* Database upgrade with a lock and safe repetition; audit log of interventions; setting "runs one after the other" in the editor.
* Workflows, runs and events paged with a total count; run view with correlation, timeline of the steps and redacted event data.
* Editor: origin (provider, plugin and contract version) and short description at the node, header with state, version, last run, test run and publish; field of a condition as a list with data type and example value; preview of the next times of a schedule in the site's time zone.
* Export a run as redacted JSON (without users and secrets); "Im Editor zeigen" (show in editor) jumps from the step to the marked node; workflow list with the last run and the errors of the last 30 days.
* Schedule: a time in the repeated hour at the end of daylight saving time now also applies at its first occurrence east of UTC (before: one hour later).
* Filter runs by period (last 24 hours to 90 days or custom dates); overview with the tile "runs · 30 days" and provider status; new page "Zeitpläne" (schedules) with the next time and the last firing in the site's time zone.
* Editor: drag building blocks onto the flow; "+ Gruppe" (add group) in the condition form (one group level, at most 20 rules); warning sign at the node when a provider is missing, an output is open or rules are invalid; "last fired" in the schedule.

= 0.1.0 =
* First version of the consumer following docs/SPEC-suite-events.md (draft 1.0.0-draft.5).
