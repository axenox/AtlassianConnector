# Connecting to Atlassian products

## Table of contents

- Setting up data connections
  - [Using a service account](Connecting_via_service_account.md)
- [Testing REST APIs](Testing_REST_APIs.md) without a data connection in the 
  model
- Once a connection works, see [Jira meta objects](../Metaobjects/index.md) to
  model Jira data, or [AI tools](../AI_tools/index.md) to let an agent write
  to Jira
- [Troubleshooting](../Troubleshooting.md) connection/certificate issues

## Quick intro

To read/write data to Atlassian products (Jira, Confluence, etc.) you need 
to use the Atlassian REST APIs. The APIs are protected by OAuth 2.0 and 
require an access token to be sent with each request.

You can either use personal accounts of your workbench users, so they are 
asked to log in to Atlassian/Jira every time the app wants to connect or 
create a service account for your integration, so the app can connect to 
Atlassian products without user interaction.

### Service accounts

The service account uses OAuth 2.0 (client credentials), so you need to 
create a service account in Atlassian and get the `client_id` and 
`client_secret`. 

See dedicated seciont [connecting via service account](Connecting_via_service_account.md) for 
more details.