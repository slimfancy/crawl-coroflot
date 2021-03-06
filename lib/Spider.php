<?php

namespace Slim;

use Exception;
use GuzzleHttp\Client;

class Spider
{
    const USERS_FILE = "storage/users.txt";

    private $stats;
    private $client;

    /**
     * Spider constructor.
     * @param $stats
     */
    public function __construct(Stat $stats)
    {
        $this->stats = $stats;
        $this->stats->loadStats();
        $this->client = new Client();
    }

    function getPage($page)
    {
        try {
            $response = $this->client->request('POST', 'http://www.coroflot.com/people/search', [
                'headers' => ['Accept' => 'application/json'],

                'form_params' => ['page_number' => $page, 'sort_by' => "1", 'currently_featured' => "false"]
            ]);

            $jsonResponse = json_decode($response->getBody(), true);
            $data = json_decode($jsonResponse['data'], true);
            Log::info("Get page ($page) success.");
            return $data['job_seeker_profiles'];
        } catch (Exception $e) {
            Log::error("Get page ($page) error.");
            return [];
        }
    }

    function downloadSaveAvatar($avatarName)
    {
        $avatarUrlPrefix = "http://s3images.coroflot.com/user_files/individual_files/avatars/";
        $avatarUrl = $avatarUrlPrefix . $avatarName;

        try {
            $avatarResponse = $this->client->get($avatarUrl);
            $extension = pathinfo($avatarName, PATHINFO_EXTENSION);

            $fileName = md5(uniqid()) . "." . $extension;
            $filePath = "storage/img/$fileName";
            file_put_contents($filePath, $avatarResponse->getBody());
            Log::info("Download avatar success: $avatarUrl");
            return $fileName;
        } catch (Exception $e) {
            Log::error("Download avatar failed: $avatarUrl");
            return false;
        }
    }

    function recordUser($firstName, $lastName, $avatar)
    {
        $firstName = trim(preg_replace("/[^a-zA-Z0-9 ]+/", "", $firstName));
        $lastName = trim(preg_replace("/[^a-zA-Z0-9 ]+/", "", $lastName));
        if(strlen($firstName) < 4) {
            $username = $firstName . " " . $lastName;
        } else {
            $username = $firstName;
        }

        file_put_contents(self::USERS_FILE, "$username,$avatar\n", FILE_APPEND);
    }

    function run($pageBegin = 0, $pageEnd = 1000)
    {
        for ($page = $pageBegin; $page < $pageEnd; $page++) {
            Log::debug("Start crawling page $page ...");
            $profiles = $this->getPage($page);
            foreach ($profiles as $profile) {
                if ($profile['avatar_image'] && !$this->stats->isDuplicated($profile['job_seeker_id'])) {
                    $avatar = $this->downloadSaveAvatar($profile['avatar_image']);
                    if ($avatar) {
                        $this->recordUser($profile['first_name'], $profile['last_name'], $avatar);
                        $this->stats->addStats($profile['job_seeker_id']);
                    }
                }
            }
        }
    }
}

