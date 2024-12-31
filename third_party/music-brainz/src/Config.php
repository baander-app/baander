<?php

declare(strict_types=1);

namespace MusicBrainz;

/**
 * The API client configuration
 */
class Config
{
    /**
     * The username of a MusicBrainz account
     *
     * @var string
     */
    private string $username;

    /**
     * The password of a MusicBrainz account
     *
     * @var string
     */
    private string $password;

    /**
     * The user agent
     * The user agent is delivered with every request. You may overwrite the default value with the name of your own
     * application.
     *
     * @var string
     */
    private string $userAgent = 'https://github.com/XenosEleatikos/MusicBrainz';

    /**
     * URL of the MusicBrainz API
     *
     * @var string
     */
    private string $apiUrl = 'https://musicbrainz.org/ws/2';

    /**
     * Returns the username.
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Sets the username.
     *
     * @param string $username The username
     *
     * @return self
     */
    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Returns the password.
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Sets the password.
     *
     * @param string $password The password
     *
     * @return self
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Returns the user agent.
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Sets the user agent.
     *
     * @param string $userAgent The user agent
     *
     * @return self
     */
    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Returns the URL of the MusicBrainz API.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Sets the URL of the MusicBrainz API.
     *
     * @param string $apiUrl The URL of the MusicBrainz API
     *
     * @return self
     */
    public function setApiUrl(string $apiUrl): Config
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }
}
