# Troubleshooting

## Local TLS/certificate troubleshooting (cURL error 60)

Symptom:

```
cURL error 60: SSL certificate problem: unable to get local issuer certificate
```

This happens on the **OAuth token request** (`auth.atlassian.com`), not the
Jira API request. `HttpConnector::performConnect()` hardcodes
`$defaults['verify'] = false`, so ordinary Jira calls skip TLS verification
by default - but the League OAuth2 client builds its own HTTP client and does
verify certificates, so it needs a CA bundle PHP can find.

Fix: download a CA bundle (e.g. https://curl.se/ca/cacert.pem) and set, in
the **PHP runtime actually used by the web server** (not just the CLI
`php.ini` - e.g. for WAMP it's `bin\apache\apache2.4.<version>\bin\php.ini`):

```ini
curl.cainfo = "C:\path\to\cacert.pem"
openssl.cafile = "C:\path\to\cacert.pem"
```

Restart Apache/the web server afterwards.
