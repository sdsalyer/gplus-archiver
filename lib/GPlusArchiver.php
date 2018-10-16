<?php
require_once '../vendor/autoload.php';

/**
 * Class GPlusArchiver
 *
 * @author Spencer Salyer
 * @since 2018.10.16
 */
class GPlusArchiver
{
    private $plus;

    public function __construct()
    {
        $client = new Google_Client();
        $client->setApplicationName('gplus-archiver');

        # These are for OAUTH
        #$client->setClientId(GAPI_CLIENT_ID);
        #$client->setClientSecret(GAPI_CLIENT_SECRET);

        # This is for API key
        $client->setDeveloperKey(GAPI_API_KEY);

        $referer = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $client->setRedirectUri($referer);
        $client->setHttpClient(new \GuzzleHttp\Client(['headers' => ['referer' => $referer]]));

        # This is for the javascript? discovery docs
        #$client->addScope('https://www.googleapis.com/discovery/v1/apis/plus/v1/rest');

        # This is for G+ API access
        $client->addScope('https://www.googleapis.com/auth/plus.login');

        # Now we can create the service client
        $this->plus = new Google_Service_Plus($client);
    }

    /**
     * Searches through Activities
     *
     * @see https://developers.google.com/+/domains/api/activities
     * @param string $query
     * @param array $params
     * @return Google_Service_Plus_ActivityFeed
     */
    public function activitiesSearch(string $query, array $params)
    {
        return $this->plus->activities->search($query, $params);
    }

    public function getComments(array $comments)
    {
        // TODO: get comments
    }

    public function getAttachments(array $attachments)
    {
        // TODO: get attachments
    }
}