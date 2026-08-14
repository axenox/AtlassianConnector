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

#### ExFace connection

Configure an HTTP data connection with the Atlassian client-credentials
provider. The provider obtains and stores the access token automatically and
adds the bearer token to Jira API requests.

```json
{
   "class": "\\exface\\UrlDataConnector\\DataConnectors\\HttpConnector",
   "url": "https://api.atlassian.com/ex/jira/YOUR_CLOUD_ID/rest/api/3/",
   "authentication": {
      "class": "\\axenox\\AtlassianConnector\\DataConnectors\\Authentication\\AtlassianClientCredentials",
      "client_id": "YOUR_CLIENT_ID",
      "client_secret": "YOUR_CLIENT_SECRET",
      "audience": "api.atlassian.com",
      "scopes": [
         "read:jira-work",
         "read:jira-user"
      ],
      "url_authorize": "https://auth.atlassian.com/authorize",
      "url_access_token": "https://auth.atlassian.com/oauth/token",
      "url_resource_owner_details": "https://api.atlassian.com/me"
   }
}
```

Use this connection as the data source of a Jira issue metaobject. A
`ReadData` action for that metaobject can then read endpoints such as
`search/jql` or `issue/PROJ-123`; authentication is performed by the
connection and does not need to be implemented in the action.

#### Local TLS troubleshooting

On local PHP installations, a token request can fail with `cURL error 60:
SSL certificate problem: unable to get local issuer certificate`. This means
the PHP runtime cannot find a trusted certificate-authority (CA) bundle to
verify `https://auth.atlassian.com/oauth/token`.

Download a current CA bundle from <https://curl.se/ca/cacert.pem> and configure
the PHP runtime used by the web server with its absolute path:

```ini
curl.cainfo = "C:\path\to\cacert.pem"
openssl.cafile = "C:\path\to\cacert.pem"
```

Restart the web server afterwards. The CLI `php.ini` and the PHP runtime used
by Apache/IIS can be different; configure the latter when the error occurs in
an ExFace page. Do not disable TLS certificate verification as a workaround:
the token request contains the OAuth client secret and returns an access token.

#### Testing the connection with a button

You can test the connection without creating a Jira metaobject by using the
Atlassian test action in a button:

```json
{
   "widget_type": "Button",
   "caption": "Test Jira connection",
   "action": {
      "class": "\\axenox\\AtlassianConnector\\Actions\\TestJiraConnection",
      "connection_alias": "your.App.JiraConnection"
   }
}
```

The action calls the Jira `myself` endpoint by default. Override `url` to
call another relative endpoint, such as
`search/jql?jql=assignee%3DcurrentUser%28%29`:

```json
{
   "class": "\\axenox\\AtlassianConnector\\Actions\\TestJiraConnection",
   "connection_alias": "your.App.JiraConnection",
   "url": "search/jql?jql=assignee%3DcurrentUser%28%29",
   "show_response": true,
   "response_max_length": 2000
}
```

The action includes the response body in its result message by default. Set
`show_response` to `false` to return only the status and summary, or adjust
`response_max_length` to limit the message size.