# Building Oro Admin Dashboard Widgets

A field guide to wiring a widget onto the Oro Platform / OroCommerce admin
dashboard, written while building the `genaker_oroai_chat` ("ORO AI
Assistant") widget in this bundle and hitting every trap below for real.
Every error message, config key, and query on this page was verified live
against this codebase — nothing here is copied from memory.

## The three rules that matter most

1. **Your widget's response HTML must contain a top-level element with
   class `widget-content`**, or Oro's loader throws `Invalid server
   response` and discards everything you rendered.
2. **A custom widget route only reaches the dashboard's client-side router
   if its name matches `fos_js_routing.routes_to_expose`, or carries
   `options: {expose: true}` itself** — otherwise "Add Widget" throws
   `The route "…" does not exist` before your controller is ever hit.
3. **The title bar, collapse icon, and drag handle are built from data your
   controller returns via `WidgetConfigs::getWidgetAttributesForTwig()`** —
   skip it and the frame renders empty.

---

## 1. Architecture at a glance

A dashboard widget is not one request — it's a config entry read twice, by
two different systems, before your Twig template ever renders:

```
dashboards.yml
   └─ read by the "Add Widget" picker
User clicks "Add Widget"
   └─ server persists an oro_dashboard_widget row, returns its config as JSON
dashboard-container-view.js: addToDashboard()
   └─ routing.generate(config.route)   ← fails here if the route isn't exposed
GET <content route>
   └─ your controller
Controller
   └─ merges WidgetConfigs attributes with your own data, renders Twig
Twig: extends widget.html.twig
   └─ wraps {% block content %} in <div class="widget-content">
abstract-widget.js: setContent()
   └─ title bar, collapse icon, drag handle attach here   ← fails here if the wrapper is missing
```

## 2. Two ways to serve content

Both end up going through the same `widget.html.twig` base and the same
frame-assembly step — pick based on whether the widget needs its own data.

### Generic route — template-only widget

No controller of your own. Oro's built-in `oro_dashboard_itemized_widget`
route resolves straight to your Twig file by convention (`widget` parameter
is injected automatically):

```yaml
dashboards:
    widgets:
        acme_launchpad:
            label: 'Acme Launchpad'
            route: oro_dashboard_itemized_widget
            route_parameters: { bundle: Acme, name: launchpad }
            icon_class: fa-rocket
            acl: acme_launchpad_view
```

This resolves to `@Acme/Dashboard/launchpad.html.twig`. Good for static or
purely presentational content — zero PHP to write or maintain.

### Custom controller — data-driven widget

Your own route and action, needed the moment the widget does more than
render static markup (queries a service, checks live state, exposes its
own sub-endpoints). This is the pattern used throughout this guide, and
the one behind the AI Assistant widget in this bundle
(`Controller/ChatController.php::widgetAction()`).

## 3. Build it, step by step

The custom-controller path, worked through for a case-study widget that
shows a live status panel. Swap the names for your own.

### Step 1 — Register the widget

One entry in your bundle's `Resources/config/oro/dashboards.yml`. The
`route` value is a route *name*, resolved in the next step.

```yaml
dashboards:
    widgets:
        acme_status_widget:
            label:       'Acme Status'
            description: 'Live status of the Acme integration'
            route:       acme_dashboard_status_widget
            route_parameters: {}
            icon:        bundles/orodashboard/img/no_icon.png
            icon_class:  fa-heartbeat
            acl:         acme_status_widget_view
```

### Step 2 — Define the route, and expose it

A plain Symfony attribute route, plus the one option that's easy to
forget: `options: {expose: true}`. See [§7](#7-exposing-the-route-to-client-side-js)
for why.

```php
#[Route(
    path: '/admin/acme/widget/status',
    name: 'acme_dashboard_status_widget',
    methods: ['GET'],
    options: ['expose' => true],
)]
#[AclAncestor('acme_status_widget_view')]
public function statusWidgetAction(): Response
```

### Step 3 — Render with `WidgetConfigs` merged in

This single merge is what supplies the title bar, the collapse state, and
the configuration panel — see [§5](#5-wiring-the-title-bar).

```php
public function __construct(
    private readonly Environment $twig,
    private readonly AcmeStatusProvider $statusProvider,
    private readonly WidgetConfigs $widgetConfigs,
) {
}

public function statusWidgetAction(): Response
{
    return new Response($this->twig->render(
        '@Acme/Widget/status.html.twig',
        array_merge(
            $this->widgetConfigs->getWidgetAttributesForTwig('acme_status_widget'),
            ['status' => $this->statusProvider->getCurrentStatus()],
        )
    ));
}
```

### Step 4 — Write the template on top of the base widget shell

Extend `@OroDashboard/Dashboard/widget.html.twig` and put your markup
inside `{% block content %}` — never render a bare `<div>` as the
top-level element. See [§4](#4-the-widget-content-contract).

```twig
{% extends '@OroDashboard/Dashboard/widget.html.twig' %}

{% set widgetType = 'acme-status' %}

{% block content %}
    <div class="acme-status-widget">
        <span class="acme-status-widget__dot acme-status-widget__dot--{{ status.level }}"></span>
        {{ status.label }}
    </div>
{% endblock %}
```

### Step 5 — Clear the cache and add it

YAML and routing changes need `bin/console cache:clear`. Then, as any user
with the widget's ACL: **Dashboard → Configure → Add Widget → Acme
Status**.

## 4. The widget-content contract

Oro's widget loader doesn't trust that whatever HTML came back is safe to
insert. It looks for one specific marker first:

```js
// abstract-widget.js
setContent: function(content) {
    const widgetContent = $(content).filter('.widget-content').first();
    if (widgetContent.length === 0) {
        throw new Error('Invalid server response: ' + content);
    }
    // … disposes old content, mounts widgetContent, shows it
}
```

`$(content)` parses your entire response into a flat set of top-level
nodes — your wrapper `<div>`, any sibling `<style>` and `<script>` tags.
`.filter('.widget-content')` only checks those top-level nodes, not
anything nested inside them. A `widget-content` class buried three levels
deep does not count.

Extending the base template (Step 4 above) satisfies this automatically —
it's the base template that renders the `<div class="widget-content">`
wrapper around your `content` block. If you render standalone for some
reason, you can also add the class by hand:
`<div class="widget-content acme-status-widget">`.

## 5. Wiring the title bar

The base template reads `widgetTitle`, falling back to
`widgetLabel|trans`. Neither exists unless you put them there:

```php
// Oro\Bundle\DashboardBundle\Model\WidgetConfigs
public function getWidgetAttributesForTwig(string $widgetName): array
{
    // reads your dashboards.yml entry for $widgetName and returns
    // widgetName, widgetLabel, widgetDescription, widgetConfiguration, …
}
```

Inject `WidgetConfigs` into your controller's constructor and merge its
output into the render context, as in Step 3 above. Miss this and the
widget still loads — it just sits in a title-less frame with no way to
identify it among the others on the dashboard.

## 6. Gating with ACL

Two checks, both pointing at the same action-type permission — one hides
the widget from the picker, one protects the endpoint if someone already
has it added and later loses the permission:

- `acl: acme_status_widget_view` in `dashboards.yml` — keeps it out of
  "Add Widget" for users without the grant.
- `#[AclAncestor('acme_status_widget_view')]` on the controller action —
  enforced on every content request, independent of the picker.
- The permission itself is registered like any other action ACL,
  typically in `Resources/config/oro/acls.yml`.

## 7. Exposing the route to client-side JS

This is the trap that produces the most confusing symptom: the route
exists, works fine when you visit it directly, and yet "Add Widget" fails
immediately.

**What you'll see**, thrown by the dashboard's client-side router, before
any HTTP request to your controller is made:

```
Uncaught Error: The route "acme_dashboard_status_widget" does not exist.
    at e.getRoute (app.js:…)
    at e.generate (app.js:…)
    at e.addToDashboard (orodashboard.js:…)
```

`php bin/console debug:router` will happily show the route existing
server-side — that's a different routing table entirely. Oro dumps only a
subset of routes into the browser's routing table, controlled by a
pattern match in `config/config.yml`:

```yaml
fos_js_routing:
    routes_to_expose: ['oro_.*', 'oropro_.*']
```

A route in your own bundle almost never matches `oro_.*`. Rather than
widen that project-wide pattern, expose the one route that needs it:

```php
#[Route(
    path: '/admin/acme/widget/status',
    name: 'acme_dashboard_status_widget',
    methods: ['GET'],
    options: ['expose' => true],
)]
```

Confirm it took with
`php bin/console debug:router acme_dashboard_status_widget` — the Options
table should list `expose: true` — then rebuild the cache. Only routes a
widget's own content or management URLs use need this; endpoints you call
from your own JS via a server-rendered URL (a `data-*` attribute, for
instance) don't go through the client router at all.

## 8. Seeding the default layout

A widget registered in `dashboards.yml` is only *available* — someone
still has to add it. To ship it pre-added for everyone, seed it onto the
shared `"main"` dashboard with a data fixture.

The default dashboard is one row per organization, not one per user —
adding a widget to it makes it appear for every user in that organization
immediately, no per-user seeding required.

```php
namespace Acme\Bundle\StatusBundle\Migrations\Data\ORM;

use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\DashboardBundle\Migrations\Data\ORM\AbstractDashboardFixture;
use Oro\Bundle\DashboardBundle\Migrations\Data\ORM\LoadDashboardData;

class LoadAcmeStatusWidgetData extends AbstractDashboardFixture implements DependentFixtureInterface
{
    #[\Override]
    public function getDependencies(): array
    {
        return [LoadDashboardData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $dashboard = $this->findAdminDashboardModel($manager, 'main');
        if (!$dashboard) {
            return;
        }
        $dashboard->addWidget($this->createWidgetModel('acme_status_widget', [0, 1]));
        $manager->flush();
    }
}
```

Oro tracks executed fixture classes by name, so this runs exactly once,
the next time `oro:platform:update` or `oro:migration:data:load` runs —
on fresh installs and already-provisioned ones alike.

### Placing it precisely

`layout_position` is `[column, row]`; widgets in the same column sort by
row, ascending. To land directly after an existing widget without
colliding, either append past the column's current max row (simplest,
position not guaranteed) or bump every later widget's row by one. Oro's
own `quick_launchpad` sidesteps the whole problem by seeding at row `10`,
deliberately past anything else in its column.

| Column 0 — before | Column 0 — after inserting at row 1 |
|---|---|
| `quick_launchpad` `[0, 0]` | `quick_launchpad` `[0, 0]` |
| `my_accounts_activity` `[0, 1]` | **`acme_status_widget` `[0, 1]`** |
| `recent_emails` `[0, 2]` | `my_accounts_activity` `[0, 2]` |
| | `recent_emails` `[0, 3]` |

Check the live layout before writing position numbers — don't guess from
reading other bundles' fixtures, their values can predate reordering done
elsewhere:

```bash
php bin/console doctrine:query:sql "SELECT w.name, w.layout_position
  FROM oro_dashboard_widget w JOIN oro_dashboard d ON d.id = w.dashboard_id
  WHERE d.name = 'main' ORDER BY w.layout_position"
```

## 9. Other things `dashboards.yml` supports

Verified against Oro's own config schema
(`Oro\Bundle\DashboardBundle\Model\Configuration`), not all exercised by
the case study above — useful for widgets with more advanced needs:

| Key | Purpose |
|---|---|
| `applicable` | Expression evaluated by the config resolver to conditionally show/hide the widget |
| `isNew` | Adds a "New" badge next to the widget's title |
| `enabled` | Kill switch — `false` removes the widget from the picker entirely |
| `configuration` | Defines form fields for a per-widget settings dialog (gear icon); each field can set `show_on_widget: true` to also render its saved value inside the widget frame |
| `configuration_dialog_options` | `resizable`, `minWidth`, `minHeight`, `title` for that settings dialog |
| `data_items` / `items` | For "data items" / "itemized" widget types (like `quick_launchpad`) — a keyed list of sub-entries, each with its own `label`, `route`, `acl`, `position` |

## 10. Debugging playbook

Match the symptom, skip the log-diving. Every row here is a real failure
signature, not a hypothetical.

| Symptom | Cause | Fix |
|---|---|---|
| Widget missing from the "Add Widget" picker | ACL not granted to the current user's role, or a typo in `dashboards.yml`'s `acl:` key | Check the role's grants for that permission id; confirm the widget key matches exactly |
| `Error: The route "…" does not exist` inside `addToDashboard` | Route name doesn't match `fos_js_routing.routes_to_expose` | Add `options: {expose: true}` to that route, clear cache |
| `Error: Invalid server response: <div …>` | Response HTML has no top-level `.widget-content` element | Extend `@OroDashboard/Dashboard/widget.html.twig`, move markup into `block content` |
| Content loads, but the title bar is blank and there's no drag handle | Controller never merged `WidgetConfigs::getWidgetAttributesForTwig()` into the render context | Merge it in (§5) — the frame is assembled from that data, not free chrome |
| The widget shows up two, three, four times after failed "Add" attempts | The server persists the `oro_dashboard_widget` row on click, before the client-side content load can fail | Delete the strays, then retest after fixing the underlying error (run via `doctrine:query:dql`, not raw SQL): `DELETE FROM Oro\Bundle\DashboardBundle\Entity\Widget w WHERE w.name = '…'` |
| Edited JS/CSS doesn't show up in the browser | `public/bundles/<bundle>` is a stale one-time copy, not a live symlink | `php bin/console assets:install --symlink public` |

---

Official reference: [doc.oroinc.com — DashboardBundle](https://doc.oroinc.com/bundles/platform/DashboardBundle/)
(linked from `vendor/oro/platform/src/Oro/Bundle/DashboardBundle/README.md`).
If a step here disagrees with your version's behavior, trust
`debug:router` and the browser console over this page.
