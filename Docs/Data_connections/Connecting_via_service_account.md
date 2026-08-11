# Connecting to Atlassian REST APIs using a service account

The service account uses OAuth 2.0 (client credentials), so before the
actual rest api call, you need to call for the token with the credentials.

#### Technical workflow

Get an access token - POST to `https://auth.atlassian.com/oauth/token` with your `client_id`, `client_secret` and `grant_type=client_credentials`. The token is short-lived (60 min), so your integration needs to request a new one when it expires.
Call the Jira REST API - instead of the usual `https://<site>.atlassian.net/rest/api/3/...`, all calls go through: https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3/...`
   with the header `Authorization: Bearer <access_token>`and cloud_id of relevant jira instance (will be shared with credentials)

#### Example

```
GET https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3/issue/PROJ-123
Authorization: Bearer <access_token>
Accept: application/json
```