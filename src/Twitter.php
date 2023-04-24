<?php

namespace EspressoDev\Twitter;

/**
 * Class Twitter
 * @package EspressoDev\Twitter
 */
class Twitter
{
    const API_URL = 'https://api.twitter.com/2/';

    const API_OAUTH_URL = 'https://twitter.com/i/oauth2/authorize';

    const API_OAUTH_TOKEN_URL = 'https://api.twitter.com/2/oauth2/token';

    const API_TOKEN_REFRESH_URL = 'https://api.twitter.com/2/oauth2/token';

    /**
     * @var string
     */
    private $_clientId;

    /**
     * @var string
     */
    private $_clientSecret;

    /**
     * @var string
     */
    private $_redirectUri;

    /**
     * @var string
     */
    private $_accesstoken;

    /**
     * @var string[]
     */
    private $_scopes = ['tweet.read', 'users.read', 'offline.access'];

    /**
     * @var string
     */
    private $_userFields = 'created_at,description,entities,id,location,name,pinned_tweet_id,profile_image_url,protected,public_metrics,url,username,verified,verified_type,withheld';

    /**
     * @var string
     */
    private $_tweetFields = 'id,text,attachments,author_id,created_at,entities,in_reply_to_user_id,public_metrics,referenced_tweets';

    /**
     * @var string
     */
    private $_mediaFields = 'duration_ms,height,media_key,preview_image_url,type,url,width,public_metrics,non_public_metrics,organic_metrics,promoted_metrics,alt_text,variants';

    /**
     * @var string
     */
    private $_expansionFields = 'attachments.poll_ids,attachments.media_keys,author_id,edit_history_tweet_ids,entities.mentions.username,geo.place_id,in_reply_to_user_id,referenced_tweets.id,referenced_tweets.id.author_id';

    /**
     * @var int
     */
    private $_timeout = 90000;

    /**
     * @var int
     */
    private $_connectTimeout = 20000;

    /**
     * Twitter constructor.
     * @param string[string]|string $config configuration parameters
     * @throws TwitterException
     */
    public function __construct($config = null)
    {
        if (is_array($config)) {
            $this->setClientId($config['clientId']);
            $this->setClientSecret($config['clientSecret']);
            $this->setRedirectUri($config['redirectUri']);

            if (isset($config['timeout'])) {
                $this->setTimeout($config['timeout']);
            }

            if (isset($config['connectTimeout'])) {
                $this->setConnectTimeout($config['connectTimeout']);
            }
        } elseif (is_string($config)) {
            // For read-only
            $this->setAccessToken($config);
        } else {
            throw new TwitterException('Error: __construct() - Configuration data is missing.');
        }
    }

    /**
     * @param string[] $scopes
     * @param string $state
     * @return string
     * @throws TwitterException
     */
    public function getLoginUrl($scopes = ['tweet.read', 'users.read', 'offline.access'], $state = '')
    {
        if (is_array($scopes) && count(array_intersect($scopes, $this->_scopes)) === count($scopes)) {
            return self::API_OAUTH_URL . '?client_id=' . $this->getClientId() . '&redirect_uri=' . urlencode($this->getRedirectUri()) . '&scope=' . implode(
                '%20',
                $scopes
            ) . '&response_type=code&code_challenge=challenge&code_challenge_method=plain' . ($state != '' ? '&state=' . $state : '&state=state');
        }

        throw new TwitterException("Error: getLoginUrl() - The parameter isn't an array or invalid scope permissions used.");
    }

    /**
     * @param int $id
     * @return object
     * @throws TwitterException
     */
    public function getUserProfile($id = 0)
    {
        if ($id === 0) {
            $id = 'me';
        }

        return $this->_makeCall('users/' . $id, ['user.fields' => $this->_userFields]);
    }

    /**
     * @param string $id
     * @param int $limit
     * @param string|null $before
     * @param string|null $after
     * @return object
     * @throws TwitterException
     */
    public function getUserMedia($id, $limit = 0, $nextToken = null)
    {
        $params = [
            'expansions' => $this->_expansionFields,
            'media.fields' => $this->_mediaFields,
            'tweet.fields' => $this->_tweetFields,
            'user.fields' => $this->_userFields
        ];

        if ($limit > 0) {
            $params['max_results'] = $limit;
        }

        if (isset($nextToken)) {
            $params['pagination_token'] = $nextToken;
        }

        return $this->_makeCall('users/' . $id . '/tweets', $params);
    }

    /**
     * @param string $tag
     * @param int $limit
     * @param string|null $before
     * @param string|null $after
     * @return object
     * @throws TwitterException
     */
    public function getSearchMedia($query, $limit = 0, $nextToken = null)
    {
        $params = [
            'query' => $query,
            'expansions' => $this->_expansionFields,
            'media.fields' => $this->_mediaFields,
            'tweet.fields' => $this->_tweetFields,
            'user.fields' => $this->_userFields
        ];

        if ($limit > 0) {
            $params['max_results'] = $limit;
        }

        if (isset($nextToken)) {
            $params['pagination_token'] = $nextToken;
        }

        return $this->_makeCall('tweets/search/recent', $params);
    }

    /**
     * @param string $id
     * @return object
     * @throws TwitterException
     */
    public function getMedia($id)
    {
        $params = [
            'ids' => $id,
            'expansions' => $this->_expansionFields,
            'media.fields' => $this->_mediaFields,
            'tweet.fields' => $this->_tweetFields,
            'user.fields' => $this->_userFields
        ];

        return $this->_makeCall('tweets', $params);
    }

    /**
     * @param object $obj
     * @return object|null
     * @throws TwitterException
     */
    public function pagination($obj)
    {
        if (is_object($obj) && !is_null($obj->paging)) {
            if (!isset($obj->paging->next)) {
                return null;
            }

            $apiCall = explode('?', $obj->paging->next);

            if (count($apiCall) < 2) {
                return null;
            }

            $function = str_replace(self::API_URL, '', $apiCall[0]);
            parse_str($apiCall[1], $params);

            // No need to include access token as this will be handled by _makeCall
            unset($params['access_token']);

            return $this->_makeCall($function, $params);
        }

        throw new TwitterException("Error: pagination() | This method doesn't support pagination.");
    }

    /**
     * @param string $code
     * @param bool $tokenOnly
     * @return object|string
     * @throws TwitterException
     */
    public function getOAuthToken($code, $tokenOnly = false)
    {
        $apiData = array(
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->getRedirectUri(),
            'code' => $code,
            'code_verifier' => 'challenge'
        );

        $result = $this->_makeOAuthCall(self::API_OAUTH_TOKEN_URL, $apiData);

        return !$tokenOnly ? $result : $result->access_token;
    }

    /**
     * @param string $token
     * @param bool $tokenOnly
     * @return object|string
     * @throws TwitterException
     */
    public function refreshToken($refreshToken, $tokenOnly = false)
    {
        $apiData = array(
            'client_id' => $this->getClientId(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        );

        $result = $this->_makeOAuthCall(self::API_TOKEN_REFRESH_URL, $apiData, 'POST');

        return !$tokenOnly ? $result : $result->access_token;
    }

    /**
     * @param string $function
     * @param string[]|null $params
     * @param string $method
     * @return object
     * @throws TwitterException
     */
    protected function _makeCall($function, $params = null, $method = 'GET')
    {
        if (!isset($this->_accesstoken)) {
            throw new TwitterException("Error: _makeCall() | $function - This method requires an authenticated users access token.");
        }

        $paramString = null;

        if (isset($params) && is_array($params)) {
            $paramString = '?' . http_build_query($params);
        }

        $apiCall = self::API_URL . $function . (('GET' === $method) ? $paramString : null);

        $headerData = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getAccessToken()
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiCall);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerData);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->_connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->_timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $jsonData = curl_exec($ch);

        if (!$jsonData) {
            throw new TwitterException('Error: _makeCall() - cURL error: ' . curl_error($ch), curl_errno($ch));
        }

        list($headerContent, $jsonData) = explode("\r\n\r\n", $jsonData, 2);

        curl_close($ch);

        return json_decode($jsonData);
    }

    /**
     * @param string $apiHost
     * @param string[] $params
     * @param string $method
     * @return object
     * @throws TwitterException
     */
    private function _makeOAuthCall($apiHost, $params, $method = 'POST')
    {
        $paramString = null;

        if (isset($params) && is_array($params)) {
            $paramString = '?' . http_build_query($params);
        }

        $apiCall = $apiHost . (('GET' === $method) ? $paramString : null);

        $basicToken = base64_encode($this->getClientId() . ':' . $this->getClientSecret());

        $headerData = [
            'Accept: application/json',
            'Authorization: Basic ' . $basicToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiCall);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->_timeout);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, count($params));
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $jsonData = curl_exec($ch);

        if (!$jsonData) {
            throw new TwitterException('Error: _makeOAuthCall() - cURL error: ' . curl_error($ch));
        }

        curl_close($ch);

        return json_decode($jsonData);
    }

    /**
     * @param string $token
     */
    public function setAccessToken($token)
    {
        $this->_accesstoken = $token;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->_accesstoken;
    }

    /**
     * @param string $clientId
     */
    public function setClientId($clientId)
    {
        $this->_clientId = $clientId;
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->_clientId;
    }

    /**
     * @param string $aclientSecret
     */
    public function setClientSecret($aclientSecret)
    {
        $this->_clientSecret = $aclientSecret;
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $this->_clientSecret;
    }

    /**
     * @param string $redirectUri
     */
    public function setRedirectUri($redirectUri)
    {
        $this->_redirectUri = $redirectUri;
    }

    /**
     * @return string
     */
    public function getRedirectUri()
    {
        return $this->_redirectUri;
    }

    /**
     * @param string $fields
     */
    public function setUserFields($fields)
    {
        $this->_userFields = $fields;
    }

    /**
     * @param string $fields
     */
    public function setTweetFields($fields)
    {
        $this->_tweetFields = $fields;
    }

    /**
     * @param string $fields
     */
    public function setExpansionFields($fields)
    {
        $this->_expansionFields = $fields;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->_timeout = $timeout;
    }

    /**
     * @param int $connectTimeout
     */
    public function setConnectTimeout($connectTimeout)
    {
        $this->_connectTimeout = $connectTimeout;
    }
}
