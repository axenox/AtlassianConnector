# Jira meta objects

See [connecting via service account](../Data_connections/Connecting_via_service_account.md)
first for how to set up the data connection and authentication. This page covers
modeling Jira data as meta objects and testing it.

## Table of contents

- [Meta objects are definitions, not storage](#meta-objects-are-definitions-not-storage)
- [The Jira Issue object](#the-jira-issue-object)
- [The Jira Comment object](#the-jira-comment-object)
- [Making the search configurable](#making-the-search-configurable-eg-search-by-ticket-key)
- [How GET/POST/PATCH/DELETE is chosen](#how-getpostpatchdelete-is-chosen)
- [Auto-importing attributes via Swagger/OpenAPI](#auto-importing-attributes-via-swaggeropenapi)

## Meta objects are definitions, not storage

A Jira meta object is a persistent model definition (attributes, data
address, mappings) stored in the ExFace metamodel DB. The ticket **rows**
themselves are never stored - they are fetched from Jira on demand into a
temporary DataSheet whenever a `ReadData` action, table widget, or AI tool
call needs them. If persistence/history is needed later, add a separate
local SQL meta object and a sync action explicitly - don't conflate the two.

## The Jira Issue object

`axenox.AtlassianConnector.JIRA_ISSUE` is a read-only object shipped with
this app (`WRITABLE_FLAG` is off - writes go through dedicated actions/AI
tools instead of `UpdateData` on this object).

Data source: HTTP connection with `AtlassianClientCredentials` (base URL
ending in `.../rest/api/3/`). Query builder:
`\exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder`.

Object data address (looks up a single issue by key):

```
search/jql?jql=key%3D%22[#key#]%22&fields=*all&expand=renderedFields
```

Object data-address properties:

```json
{
  "swagger_component": "IssueBean",
  "response_data_path": "issues",
  "response_total_count_path": "total",
  "request_remote_pagination": false,
  "force_filtering": true
}
```

`response_data_path` is required because Jira wraps rows in an `issues`
array - without it ExFace won't find any rows. `force_filtering` makes sure
an unfiltered request (which would try to fetch every issue in the Jira
instance) is never sent by accident.

`fields=*all` requests every field Jira has for the issue, so no attribute
needs a matching `&fields=...` entry in the URL. If you copy this object to
build a narrower one (e.g. a JQL search across many issues instead of a
single-key lookup), remember that Jira only returns the fields you ask for -
replace `*all` with an explicit, comma-separated field list.

### Attributes

Attribute data addresses are JSON paths (`/`-separated for nested values)
resolved against the response of the URL above:

| Attribute alias         | Data address                    | Notes                                   |
|--------------------------|----------------------------------|------------------------------------------|
| `id`                     | `id`                             | UID attribute                             |
| `key`                    | `key`                            | Used in the object's data address placeholder |
| `self`                   | `self`                           | URL of the issue                          |
| `summary`                | `fields/summary`                 |                                            |
| `description`            | `fields/description`             | Raw ADF (Atlassian Document Format)       |
| `description_rendered`   | `renderedFields/description`     | HTML rendering of the description         |
| `description_markdown`   | *(calculated)*                   | `=axenox.AtlassianConnector.AdfToMarkdown(description)` - Markdown version, easier for LLMs to consume and avoids odd HTML rendering |
| `status`                 | `fields/status/name`             |                                            |
| `priority`               | `fields/priority/name`           |                                            |
| `assignee`               | `fields/assignee/displayName`    |                                            |
| `creator`                | `fields/creator/displayName`     |                                            |
| `reporter`                | `fields/reporter/displayName`    |                                            |
| `project`                | `fields/project/name`            |                                            |
| `fields`                 | `fields`                         | Raw fields object, mostly for debugging   |
| `operations`             | `operations`                     | Operations that can be performed on the issue |
| `schema`                 | `schema`                         | Schema describing each field on the issue |
| `changelog`              | `changelog`                      | Changelogs associated with the issue      |
| `renderedFields`         | `renderedFields`                 | Rendered value of each field on the issue |
| `expand`                 | `expand`                         | Expand options included in the response   |
| `versionedRepresentations`| `versionedRepresentations`      | Field versions                            |
| `names`                  | `names`                          | ID/name of each field on the issue        |
| `editmeta`               | `editmeta`                       | Metadata for fields that can be amended   |
| `properties`             | `properties`                     | Issue properties requested                |
| `transitions`            | `transitions`                    | Transitions that can be performed         |

Attribute aliases match the Jira REST API field names exactly (lower camel
case, e.g. `renderedFields`), rather than being renamed to ExFace's usual
uppercase convention - this keeps the mapping to the Swagger/OpenAPI import
(see below) obvious.

`currentUser()` in JQL - if you build your own search object using it -
refers to the identity behind the OAuth token, i.e. the service account, not
necessarily a specific human Jira user.

## The Jira Comment object

`axenox.AtlassianConnector.JIRA_COMMENT` reads the comments related to one
Jira issue. Its data address uses the required issue-key placeholder:

```
issue/[#issue_key#]/comment
```

The object reads rows from Jira's `comments` response property and uses
`total` as the total-count path. `force_filtering` prevents a request unless
an issue key is supplied.

`issue_key` is a relation to `JIRA_ISSUE.key`. Its exported cardinality is
empty, so the model loader applies the `N1` (many-to-one) default. It can be
adjusted through the model editor. Its data address is
`[#~urlplaceholder:issue_key#]`, which exposes the issue key used in the URL
as an attribute on every returned comment. The reverse relation available on
`JIRA_ISSUE` is `JIRA_COMMENT[issue_key]` and represents all comments of the
issue.

| Attribute alias | Data address                         | Notes                         |
|-----------------|--------------------------------------|-------------------------------|
| `issue_key`     | `[#~urlplaceholder:issue_key#]`      | Relation to `JIRA_ISSUE.key` |
| `id`            | `id`                                 | UID attribute                 |
| `body`          | `body`                               | Raw ADF comment body          |
| `body_markdown` | *(calculated)*                       | `=axenox.AtlassianConnector.AdfToMarkdown(body)` |
| `author`        | `author/displayName`                 | Author display name           |
| `created`       | `created`                            | Creation time                 |
| `updated`       | `updated`                            | Last update time              |

Always filter `issue_key` when reading the object, for example:

```json
{
  "widget_type": "DataTable",
  "object_alias": "axenox.AtlassianConnector.JIRA_COMMENT",
  "filters": [
    {"attribute_alias": "issue_key", "value": "ABC-123", "required": true}
  ],
  "columns": [
    {"attribute_alias": "author"},
    {"attribute_alias": "created"},
    {"attribute_alias": "body_markdown"}
  ]
}
```

## Making the search configurable (e.g. search by ticket key)

The query builder replaces `[#alias#]` placeholders in the object's data
address with the value of an active filter on that attribute
(`AbstractUrlBuilder::replacePlaceholdersInUrl()`). This is the supported way
to turn a fixed endpoint into a parameterized one - no action code needed.
The placeholder name must match the attribute alias exactly, including case
(e.g. `[#key#]`, not `[#KEY#]`).

Optional default value syntax: `[#key|??default#]` (`IfNullModifier`).

### Using it on a page

```json
{
  "widget_type": "DataTable",
  "object_alias": "axenox.AtlassianConnector.JIRA_ISSUE",
  "filters": [ {"attribute_alias": "key", "required": true} ],
  "columns": [
    {"attribute_alias": "key"}, {"attribute_alias": "summary"},
    {"attribute_alias": "status"}, {"attribute_alias": "priority"},
    {"attribute_alias": "assignee"}
  ]
}
```

## Auto-importing attributes via Swagger/OpenAPI

`HttpConnector` uses `SwaggerModelBuilder` automatically once `swagger_url`
is set on the connection. Jira Cloud's public OpenAPI v3 definition:

```
https://dac-static.atlassian.com/cloud/jira/platform/swagger-v3.v3.json
```

On the object, set `swagger_component` (e.g. `IssueBean`) since the object's
`data_address` is a search URL, not a schema component name. Generated
attributes still need `response_data_path: "issues"` and manual path
adjustments (e.g. `fields/status/name`) - the importer only knows the schema,
not that rows live inside Jira's `issues[]` wrapper.
