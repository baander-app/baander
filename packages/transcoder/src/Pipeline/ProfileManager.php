<?php

namespace Baander\Transcoder\Pipeline;

use Baander\Common\Streaming\VideoProfile;

class ProfileManager
{
    /**
     * Fetch default DASH/ABR profiles
     */
    public function getDASHProfiles(): array
    {
        return [
            [3840, 2160, 9000, 320],
            [1920, 1080, 5000, 256],  // 1080p
            [1280, 720, 3000, 128],   // 720p
            [854, 480, 1500, 96],     // 480p
            [640, 360, 800, 64],      // 360p
        ];
    }

    public function getHLSProfiles(): array
    {
        return [
            new VideoProfile('360p', 640, 360, 600),  // 360p at 600kbps
            new VideoProfile('480p', 854, 480, 1000), // 480p at 1Mbps
            new VideoProfile('720p', 1280, 720, 3000), // 720p at 3Mbps
            new VideoProfile('1080p', 1920, 1080, 5000), // 1080p at 5Mbps
        ];
    }
}