<?php
namespace axenox\AtlassianConnector\DataConnectors\Authentication;

use axenox\OAuth2Connector\CommonLogic\Security\AuthenticationToken\OAuth2AuthenticatedToken;
use axenox\OAuth2Connector\CommonLogic\Security\AuthenticationToken\OAuth2RequestToken;
use axenox\OAuth2Connector\DataConnectors\Authentication\OAuth2;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\RequestInterface;

/**
 * Authenticates Atlassian REST API requests using the OAuth2 client credentials grant.
 *
 * Configure this provider in an HTTP connection with the OAuth client ID,
 * client secret, token endpoint and Jira scopes. Before a Jira request is sent,
 * the provider obtains a token when none is stored or the stored token expired,
 * then adds it as a bearer token to the request.
 *
 * @author saskia.hustinx 
 */
class AtlassianClientCredentials extends OAuth2
{
    private $audience = 'api.atlassian.com';

    /**
     * The audience requested from Atlassian's token endpoint.
     *
     * @uxon-property audience
     * @uxon-type string
     * @uxon-default api.atlassian.com
     *
     * @param string $value
    * @return AtlassianClientCredentials
     */
    public function setAudience(string $value): AtlassianClientCredentials
    {
        $this->audience = $value;
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token): AuthenticationTokenInterface
    {
        if ($token instanceof OAuth2AuthenticatedToken && ! $token->getAccessToken()->hasExpired()) {
            return $token;
        }

        $accessToken = $this->getOAuthProvider()->getAccessToken('client_credentials', [
            'scope' => implode(' ', $this->getScopes()),
            'audience' => $this->audience
        ]);
        $this->setToken($accessToken);

        return new OAuth2AuthenticatedToken(
            'atlassian',
            $accessToken,
            $token->getFacade() ?? $this->getOAuthClientFacade()
        );
    }

    /**
     * Adds the current access token to a request and obtains a new one when necessary.
     *
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function signRequest(RequestInterface $request): RequestInterface
    {
        if ($this->needsSigning($request) === false) {
            return $request;
        }

        $token = $this->getTokenStored();
        if ($token === null || $token->hasExpired()) {
            $requestToken = new OAuth2RequestToken(
                new ServerRequest($request->getMethod(), $request->getUri()),
                '',
                null
            );
            $authenticatedToken = $this->getConnection()->authenticate(
                $requestToken,
                true,
                $this->getWorkbench()->getSecurity()->getAuthenticatedUser(),
                true
            );
            if (! $authenticatedToken instanceof OAuth2AuthenticatedToken) {
                throw new AuthenticationFailedError($this->getConnection(), 'Atlassian authentication did not return an OAuth2 access token!');
            }
            $token = $authenticatedToken->getAccessToken();
        }

        return $request->withHeader('Authorization', 'Bearer ' . $token->getToken());
    }
}