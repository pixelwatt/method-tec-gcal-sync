# Method – Google Calendar Sync for The Events Calendar

Keeps a rolling window of events from one or more **public Google Calendars** synced into The Events Calendar. Built to sit alongside [Method – Mapbox for The Events Calendar](https://github.com/pixelwatt/method-tec-mapbox): venues are created through TEC's own venue API, so the Mapbox plugin geocodes them with no coupling between the two.

- **Field-level three-way merge.** Edit a synced event locally and that field stops following Google; every other field keeps syncing. "Reset to Google values" puts it back.
- **Source deletions are honoured.** An event removed from Google is trashed (or deleted) locally. Events you trash locally are never re-created.
- **Venues matched, then created.** Google's one-line `location` resolves to an existing venue by alias → name → street address; only then is a venue created.
- **Rolling window.** Only events from *lookback* days ago through *window* days ahead are touched.
- **Multiple calendars**, each optionally tagged with an Event Category.
- Runs via Action Scheduler (bundled with TEC), WP-Cron, WP-CLI, the settings page, or an external cron hitting a REST endpoint.

Requires The Events Calendar and PHP 7.4+. CMB2 is needed only for the settings UI.

## Google setup (free tier)

The Calendar API has no billing requirement and a default quota of 1,000,000 queries/day. One sync is one `events.list` call per calendar (plus one `events.get` per event that vanished from the window), so an hourly sync of three calendars is ~75 calls/day.

1. [console.cloud.google.com](https://console.cloud.google.com) → create a project → **APIs & Services → Library** → enable **Google Calendar API**.
2. **APIs & Services → Credentials → Create credentials → API key.** Under *API restrictions*, restrict the key to the Calendar API. Leave *Application restrictions* at **None** — HTTP-referrer restrictions don't apply to server-side requests, and IP restrictions break on hosts with floating egress IPs (WP Engine).
3. In Google Calendar, for each calendar: **Settings → Access permissions for events → Make available to public** (the "See all event details" option).
4. Same settings page, **Integrate calendar → Calendar ID** — that is what you paste into the plugin (looks like `xxxx@group.calendar.google.com`).

## Configuration

Any of these, in increasing precedence:

**Standalone page** — Events → Google Calendar Sync (CMB2 options page, option key `method_tec_gcal_sync`).

**Method options page** — append the same fields to Method's unified options page (keys land in `method_options` prefixed `gcal_`), mirroring cmb2-mapbox's pattern:

```php
add_filter( 'method_gcal_sync_use_method', '__return_true' );
```

**Code only** — overrides whatever is stored; no CMB2 needed:

```php
add_filter( 'method_gcal_sync_settings', function ( array $s ): array {
    $s['api_key']   = 'AIza…';
    $s['calendars'] = [
        [ 'calendar_id' => 'xxx@group.calendar.google.com', 'label' => 'City Council', 'category' => 'council' ],
        [ 'calendar_id' => 'yyy@group.calendar.google.com', 'label' => 'Parks & Rec' ],
    ];
    $s['window_days']   = 120;   // default 90
    $s['lookback_days'] = 1;     // default 1
    $s['interval']      = 30;    // minutes; 0 disables the scheduled run (default 60)
    $s['post_status']   = 'publish';
    $s['delete_mode']   = 'trash'; // or 'delete'
    $s['author']        = 0;     // post author for new events
    return $s;
} );
```

Save settings, then use **Preview (dry run)** on the settings page before the first real **Sync now**. The dry run lists every create/update/trash it would perform and every venue it would create.

## How a sync works

For each calendar it fetches every single-instance event in the window (`singleEvents=true` expands recurrences server-side; each instance has a stable id and becomes an independent TEC event), then:

| Google says | Local state | Result |
|---|---|---|
| event present | no local post | create |
| event present | local post | per-field merge (below) |
| event present | local post in Trash | skip — a trashed event is never resurrected |
| `status: cancelled` | local post | trash/delete |
| absent from window | local post with start inside the window | one `events.get`: gone → trash/delete; still exists → it was moved, so update |
| absent from window | local post with start outside the window | left alone |

### The merge

Each synced post keeps `_gcs_snapshot`: for every synced field, the value the importer last wrote **as it landed in the database** (so kses/slashing round-trips don't read as edits), or for a field it left alone, what Google had at the time. On each run, per field:

| snapshot | local vs snapshot | action |
|---|---|---|
| none (new, or after reset) | — | write source |
| exists | equal → not edited locally | write source if it changed |
| exists | different → edited locally | keep local; snapshot records the current source value |

Synced fields: `title`, `content`, `start_date`, `end_date`, `all_day`, `timezone`, `venue`, `url` (from Google's `source.url`, when set). Dates are written as a set through TEC's ORM so UTC and duration are recomputed; a locally-edited end date survives a source start-date change.

The event editor shows a **Google Calendar** box: source link, Google's raw location string, last sync, which fields are currently being preserved, and a **Reset to Google values** button (deletes the snapshot and re-syncs that one event). The events list has a column with the calendar label and a pencil icon when local edits are being kept.

Cancellations: Google has no "cancelled but still on the calendar" state for the owner's own events — in the API, `cancelled` means deleted. If an editor retitles an event "CANCELED – …", that's just a title change and syncs like one.

### Venues

Google's `location` is a free-text line. Resolution order, first hit wins:

1. **Alias** — a venue with the exact string in its `_gcs_location_alias` meta. Every match or create adds one, and the venue edit screen has a textarea to add your own, so you can point a Google location at an existing venue instead of letting the sync create another.
2. **Match** — normalised comparison (case, punctuation, `Street`→`st`, `Circle`→`cir`, `S.W.`→`sw`, …) against existing venue names and street addresses; a street match also requires the city to agree when both sides have one.
3. **Create** — the string is split on commas into name / street / city / state / ZIP / country (handles `Name, 123 Street, City, ST 12345, USA`, `Name, City, ST`, `City, ST`, bare names like `Zoom`) and created with `Tribe__Events__Venue::instance()->create( $data, 'publish', true )`. That fires `tribe_events_venue_created( $venue_id, $data )` with TEC's classic keys (`Address`, `City`, `StateProvince`, `Zip`, `Country`), which is exactly what method-tec-mapbox's `on_venue_save` → `build_address()` consumes — so new venues are geocoded and get their cmb2-mapbox pin on creation. The `true` turns on TEC's own title+address duplicate finder as a final guard.

A venue on a synced event is a synced field: reassign it locally and it's preserved like any other edit. If a venue can't be resolved or created, the local venue is left untouched rather than cleared.

## Running it

- **Scheduled:** Action Scheduler when available (it is, with TEC), otherwise WP-Cron, at the configured interval. The schedule is reconciled on `init`, so changing the interval takes effect on the next page load.
- **Settings page:** *Sync now* / *Preview (dry run)*; the last run and the last preview are shown with their full line-by-line log.
- **WP-CLI:** `wp method gcal-sync [--dry-run] [--calendar=<id>[,<id>]] [--post=<id> [--reset]] [--quiet-lines]`
- **REST (for an external cron):** `GET /wp-json/method-gcal-sync/v1/run?key=SECRET[&calendar=ID][&dry_run=1]` — the secret is shown on the settings page. On WP Engine, pointing a real cron at this is more reliable than depending on WP-Cron.

A transient lock prevents overlapping runs. Each run's report is stored in the `method_tec_gcal_sync_last_run` option.

## Hooks

Filters:

| Filter | Purpose |
|---|---|
| `method_gcal_sync_settings( array $settings )` | Final say on every setting. |
| `method_gcal_sync_use_method( bool )` | Put the fields on Method's options page. |
| `method_gcal_sync_settings_url( string )` | Where "back to settings" goes in Method mode. |
| `method_gcal_sync_rest_secret( string )` | Override the REST key. |
| `method_gcal_sync_source_fields( array $fields, array $item, array $calendar )` | Change what's considered the source value of each synced field (e.g. prefix titles, inject cost). |
| `method_gcal_sync_event_args( array $args, array $item, array $calendar, int $post_id )` | The `tribe_events()->set_args()` payload, before create/update. |
| `method_gcal_sync_resolve_venue( int $venue_id, string $location )` | Return a venue id to short-circuit resolution. |
| `method_gcal_sync_parse_location( array $parts, string $location )` | Adjust the parsed name/address/city/state/zip/country. |
| `method_gcal_sync_venue_data( array $data, string $location, array $parts )` | The TEC venue data array before creation. |

Actions:

| Action | Fires |
|---|---|
| `method_gcal_sync_before_run( array $settings, bool $dry_run )` | Start of a run. |
| `method_gcal_sync_after_run( Report $report, array $settings, bool $dry_run )` | End of a run. |
| `method_gcal_sync_event_synced( int $post_id, array $item, bool $created, array $written_fields, array $preserved_fields )` | After each create/update. |
| `method_gcal_sync_before_remove( int $post_id, string $reason )` | Before a trash/delete. |
| `method_gcal_sync_venue_created( int $venue_id, string $location, array $parts )` | After a venue is created. |

## Meta reference

Events: `_gcs_calendar_id`, `_gcs_event_id` (Google instance id), `_gcs_ical_uid`, `_gcs_recurring_id`, `_gcs_updated` (Google's `updated`), `_gcs_link` (`htmlLink`), `_gcs_location` (raw string), `_gcs_synced` (timestamp), `_gcs_snapshot` (merge state).
Venues: `_gcs_location_alias` (repeated), `_gcs_created` (set only on venues this plugin made).

## Known limits

- Recurring series are expanded into independent events. If a series is restructured on Google (start time of the whole series changed), Google issues new instance ids; the old instances are trashed and the new ones created, so local edits on those instances are lost.
- Events already on the site before the plugin ran are not adopted; the first sync creates fresh posts for everything in the window. Trash the originals or narrow the window first.
- Images in Google descriptions are not imported (Google strips them from the API's `description` anyway).
- Updates go through `tribe_events()->where( 'id', $id )->set_args( … )->save()`. If a TEC release changes that path, `write()` in `class-sync.php` is the one place to adjust.
