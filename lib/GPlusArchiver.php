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

    public function getComments(string $activityId)
    {
        $comments = array();
        $pageToken = null;
        do {
            $params = array(
                'sortOrder' => 'ascending',   # ascending or descending
                'maxResults' => '20',    # 500 max on comments
                'pageToken' => $pageToken
            );
            $results = $this->plus->comments->listComments($activityId, $params);

            if (!$results) {
                return $comments;
            }

            if (count($results['items']) == 0) {
                # Known bug: https://code.google.com/archive/p/google-plus-platform/issues/406
                $pageToken = null;
            } else {
                $pageToken = $results['nextPageToken'];
                foreach ($results['items'] as $item) {
                    $row = array(
                        'id' => $activityId,
                        'etag' => $item['etag'], # not entirely sure this will be useful
                        'published' => $item['published'],
                        'updated' => $item['updated'],
                        # 'url' => $item['selfLink'],  # useless
                        'author' => [
                            'displayName' => $item['actor']['displayName'],
                            'id' => $item['actor']['id'],
                            'url' => $item['actor']['url'],
                            'profileImage' => $item['actor']['image']['url']
                        ],
                        'content' => $item['object']['content']
                    );
                    array_push($comments, $row);
                }
            }
        } while (!is_null($pageToken));

        return $comments;
    }

    public function getAttachments(array $attachments)
    {
        // TODO: get attachments
    }

    /**
     * Parse the name and category out of an access->description value
     * e.g. 'Lone Wolf Roleplaying (Actual Plays)' would output
     *      $name = 'Lone Wolf Roleplaying'
     *      $category = 'Actual Plays'
     *
     * @param string $communityDescription
     * @param string $name
     * @param string $category
     */
    public static function parseCommunityNameAndCategory(string $communityDescription, string &$name, string &$category)
    {
        $lastOpenParen = strrpos($communityDescription, '(');
        $lastCloseParen = strrpos($communityDescription, ')');
        $name = substr($communityDescription, 0, $lastOpenParen - 1);
        $category = substr($communityDescription, $lastOpenParen + 1, $lastCloseParen - $lastOpenParen - 1);
    }
}